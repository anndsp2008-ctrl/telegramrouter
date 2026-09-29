<?php declare(strict_types=1);

namespace App\Reporting;

final class ResultWorker
{
    public static function runOnce(): void
    {
        $settings = Repository::settings();
        error_log('TMR_REPORTING_RUN_STATE ' . json_encode([
            'enabled' => !empty($settings['enabled']),
            'check_results' => !empty($settings['check_results']),
            'queue' => Repository::pendingQueueSummary(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (empty($settings['enabled']) || empty($settings['check_results'])) {
            Repository::setState('worker_status', 'disabled');
            Repository::setState('worker_last_run_at', gmdate('Y-m-d H:i:s'));
            return;
        }

        $apiKey = class_exists(\App\SportsApiIntegration::class)
            ? \App\SportsApiIntegration::apiFootballKey()
            : trim((string)(getenv('API_FOOTBALL_KEY') ?: ''));
        if ($apiKey === '') {
            error_log('TMR_REPORTING_API_KEY_MISSING');
            Repository::setState('worker_status', 'api_key_missing');
            Repository::setState('worker_last_run_at', gmdate('Y-m-d H:i:s'));
            return;
        }

        $api = new ApiFootballClient($apiKey);
        $matcher = new FixtureMatcher($api);

        $forcedPending = Repository::forcePendingChecksOnce('manual_force_pending_20260929_v1');
        if ($forcedPending > 0) {
            error_log('TMR_REPORTING_FORCE_PENDING ' . $forcedPending);
        }

        // Garante que tickets já existentes também aguardem a janela pós-jogo,
        // sem consumir a API antes do horário esperado de término.
        $normalizedMatches = Repository::normalizeStoredMatchNamesOnce();
        if ($normalizedMatches > 0) {
            error_log('TMR_REPORTING_MATCHES_NORMALIZED ' . $normalizedMatches);
        }

        Repository::deferPendingFixtureChecksToPostMatchWindow();
        Repository::restoreImmediateUnsupportedReviews();
        Repository::reopenLegacyConfidenceReviewsOnce();
        Repository::restorePrematureLookupReviews();
        Repository::restoreUnicodeDashLookupFailures();
        $requeued = Repository::requeuePendingUnmatchedForRetryPolicyOnce();
        if ($requeued > 0) {
            error_log('TMR_REPORTING_UNMATCHED_REQUEUED ' . $requeued);
        }

        $unmatchedLegs = Repository::unmatchedLegs();
        error_log('TMR_REPORTING_UNMATCHED_DUE ' . count($unmatchedLegs));
        foreach ($unmatchedLegs as $leg) {
            try {
                if (!self::isFootball((string)($leg['sport'] ?? ''))) {
                    Repository::settleLeg((int)$leg['id'], [
                        'status'=>SettlementEngine::REVIEW,
                        'return_factor'=>null,
                        'observed'=>null,
                        'reason'=>'Esporte ainda não suportado pela fonte de resultados configurada.',
                    ], []);
                    continue;
                }

                $lookup = $matcher->matchDetailed($leg);
                $lookupStatus = (string)($lookup['status'] ?? 'not_found');

                if ($lookupStatus === 'matched' && is_array($lookup['match'] ?? null)) {
                    Repository::attachFixture((int)$leg['id'], $lookup['match']);
                    continue;
                }

                $attempts = Repository::recordLookupFailure(
                    (int)$leg['id'],
                    $lookupStatus
                );

                if ($attempts >= 2) {
                    $reason = match ($lookupStatus) {
                        'ambiguous' => 'Mais de uma partida compatível permaneceu ambígua após duas tentativas espaçadas.',
                        'invalid_match_name' => 'Nome da partida insuficiente para identificar o evento após duas tentativas.',
                        'low_confidence' => 'Partida encontrada com confiança insuficiente após duas tentativas espaçadas.',
                        default => 'Partida/horário oficial não identificado após duas consultas espaçadas.',
                    };

                    Repository::settleLeg((int)$leg['id'], [
                        'status'=>SettlementEngine::REVIEW,
                        'return_factor'=>null,
                        'observed'=>null,
                        'reason'=>$reason,
                    ], [
                        'phase'=>'kickoff_lookup',
                        'lookup_status'=>$lookupStatus,
                        'attempts'=>$attempts,
                        'best_confidence'=>$lookup['best_confidence'] ?? null,
                        'second_confidence'=>$lookup['second_confidence'] ?? null,
                        'team_a_resolved'=>$lookup['team_a_resolved'] ?? null,
                        'team_b_resolved'=>$lookup['team_b_resolved'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('TMR_REPORTING_FIXTURE_MATCH_NON_FATAL ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 180));
                Repository::rescheduleUnmatchedLeg((int)($leg['id'] ?? 0));
            }
        }

        $dueFixtureIds = Repository::dueFixtureIds();
        error_log('TMR_REPORTING_FIXTURES_DUE ' . count($dueFixtureIds));
        foreach ($dueFixtureIds as $fixtureId) {
            try {
                // Hard stop: se outra execução já liquidou este fixture depois
                // da montagem da fila, não faça nova chamada à API-Football.
                if (!Repository::fixtureHasPendingLegs($fixtureId)) {
                    error_log('TMR_REPORTING_FIXTURE_SKIP_SETTLED ' . $fixtureId);
                    continue;
                }

                $fixture = $api->fixture($fixtureId);
                if ($fixture === []) {
                    Repository::rescheduleFixtureAtNextSweep($fixtureId);
                    continue;
                }

                $short = strtoupper(trim((string)($fixture['fixture']['status']['short'] ?? '')));
                $elapsed = self::number($fixture['fixture']['status']['elapsed'] ?? null);

                // Sparse API policy:
                // - nenhuma consulta periódica durante a partida;
                // - a chamada normal ocorre em kickoff + 125 min;
                // - se ainda estiver em andamento, agenda UMA nova consulta para
                //   aproximadamente 15 min após o término projetado;
                // - estados indefinidos/adiados aguardam o sweep noturno.
                if (in_array($short, ['PST','TBD','NS','BT','INT','SUSP'], true)) {
                    Repository::rescheduleFixtureAtNextSweep($fixtureId);
                    continue;
                }
                if (in_array($short, ['1H','HT','2H','ET'], true)) {
                    Repository::rescheduleFixture(
                        $fixtureId,
                        self::postMatchRetryMinutes($short, $elapsed)
                    );
                    continue;
                }
                if (in_array($short, ['CANC','ABD','AWD','WO'], true)) {
                    Repository::markFixtureReview($fixtureId, 'Partida encerrada em status especial: ' . $short);
                    continue;
                }
                if (!in_array($short, ['FT','AET','PEN'], true)) {
                    Repository::rescheduleFixtureAtNextSweep($fixtureId);
                    continue;
                }

                $pendingLegs = Repository::pendingLegsForFixture($fixtureId);
                $statistics = self::needsStatistics($pendingLegs)
                    ? $api->statistics($fixtureId)
                    : [];
                $stats = self::buildStats($fixture, $statistics);
                $stats['fixture_status']=$short;
                foreach ($pendingLegs as $leg) {
                    $result = SettlementEngine::settle($leg, $stats);
                    Repository::settleLeg((int)$leg['id'], $result, [
                        'fixture_id'=>$fixtureId,
                        'fixture_status'=>$short,
                        'stats'=>$stats,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('TMR_REPORTING_SETTLEMENT_NON_FATAL ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 180));
                Repository::rescheduleFixtureAtNextSweep($fixtureId);
            }
        }

        Repository::setState('worker_status', 'ok');
        Repository::setState('worker_last_run_at', gmdate('Y-m-d H:i:s'));
        error_log('TMR_REPORTING_RUN_DONE');
    }

    private static function needsStatistics(array $legs): bool
    {
        foreach ($legs as $leg) {
            $marketKey = (string)($leg['market_key'] ?? '');
            if (preg_match('/(?:corners|fouls|cards)/', $marketKey) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function postMatchRetryMinutes(string $status, ?float $elapsed): int
    {
        $elapsed = $elapsed !== null ? max(0.0, $elapsed) : null;

        return match ($status) {
            '1H' => max(30, (int)ceil(120 - ($elapsed ?? 45))),
            'HT' => 60,
            '2H' => max(15, (int)ceil(105 - ($elapsed ?? 75))),
            'ET' => max(15, (int)ceil(135 - ($elapsed ?? 105))),
            default => 60,
        };
    }

    private static function buildStats(array $fixture, array $statistics): array
    {
        $homeId = (int)($fixture['teams']['home']['id'] ?? 0);
        $awayId = (int)($fixture['teams']['away']['id'] ?? 0);
        $homeStats = is_array($statistics[$homeId] ?? null) ? $statistics[$homeId] : [];
        $awayStats = is_array($statistics[$awayId] ?? null) ? $statistics[$awayId] : [];

        $fulltimeHome = self::number($fixture['score']['fulltime']['home'] ?? null);
        $fulltimeAway = self::number($fixture['score']['fulltime']['away'] ?? null);

        return [
            'home_team'=>(string)($fixture['teams']['home']['name'] ?? ''),
            'away_team'=>(string)($fixture['teams']['away']['name'] ?? ''),
            'goals_home'=>$fulltimeHome ?? self::number($fixture['goals']['home'] ?? null),
            'goals_away'=>$fulltimeAway ?? self::number($fixture['goals']['away'] ?? null),
            'corners_home'=>self::number($homeStats['corners'] ?? null),
            'corners_away'=>self::number($awayStats['corners'] ?? null),
            'fouls_home'=>self::number($homeStats['fouls'] ?? null),
            'fouls_away'=>self::number($awayStats['fouls'] ?? null),
            'yellow_cards_home'=>self::number($homeStats['yellow_cards'] ?? null),
            'yellow_cards_away'=>self::number($awayStats['yellow_cards'] ?? null),
            'red_cards_home'=>self::number($homeStats['red_cards'] ?? null),
            'red_cards_away'=>self::number($awayStats['red_cards'] ?? null),
        ];
    }

    private static function isFootball(string $sport): bool
    {
        $sport = mb_strtolower(trim($sport), 'UTF-8');
        if ($sport === '') {
            return true;
        }
        return preg_match('/\b(futebol|football|soccer|fútbol|futbol)\b/u', $sport) === 1;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}
