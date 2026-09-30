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

        $footballDataKey = class_exists(\App\SportsApiIntegration::class)
            ? \App\SportsApiIntegration::footballDataKey()
            : trim((string)(getenv('FOOTBALL_DATA_TOKEN') ?: ''));
        $fallbackApi = $footballDataKey !== '' ? new FootballDataClient($footballDataKey) : null;
        $fallbackMatcher = $fallbackApi !== null ? new FixtureMatcher($fallbackApi) : null;

        $forcedPending = Repository::forcePendingChecksOnce('manual_force_pending_20260929_v2_date_lookup');
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

                $matched = self::matchWithFallback($leg, $matcher, $fallbackMatcher);
                $lookup = is_array($matched['lookup'] ?? null) ? $matched['lookup'] : ['status'=>'provider_error'];
                $lookupProvider = (string)($matched['provider'] ?? 'api_football');
                $lookupStatus = (string)($lookup['status'] ?? 'not_found');

                if ($lookupStatus === 'provider_error') {
                    Repository::rescheduleUnmatchedLeg((int)($leg['id'] ?? 0));
                    continue;
                }

                if ($lookupStatus === 'matched' && is_array($lookup['match'] ?? null)) {
                    Repository::attachFixture((int)$leg['id'], $lookup['match'], $lookupProvider);
                    if ($lookupProvider === 'football_data') {
                        error_log('TMR_REPORTING_FALLBACK_MATCHED football_data leg=' . (int)$leg['id']);
                    }
                    continue;
                }

                $attempts = Repository::recordLookupFailure(
                    (int)$leg['id'],
                    $lookupStatus
                );

                if ($attempts >= 3) {
                    $reason = match ($lookupStatus) {
                        'ambiguous' => 'Mais de uma partida compatível permaneceu ambígua após três tentativas controladas.',
                        'invalid_match_name' => 'Nome da partida insuficiente para identificar o evento após três tentativas controladas.',
                        'low_confidence' => 'Partida encontrada com confiança insuficiente após três tentativas controladas.',
                        default => 'Partida/horário oficial não identificado após três tentativas controladas.',
                    };

                    Repository::settleLeg((int)$leg['id'], [
                        'status'=>SettlementEngine::REVIEW,
                        'return_factor'=>null,
                        'observed'=>null,
                        'reason'=>$reason,
                    ], [
                        'phase'=>'kickoff_lookup',
                        'lookup_status'=>$lookupStatus,
                        'lookup_provider'=>$lookupProvider,
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

        $dueFixtures = Repository::dueFixtures();
        error_log('TMR_REPORTING_FIXTURES_DUE ' . count($dueFixtures));
        foreach ($dueFixtures as $dueFixture) {
            $storedProvider = (string)($dueFixture['provider'] ?? 'api_football');
            $fixtureId = (int)($dueFixture['fixture_id'] ?? 0);
            if ($fixtureId <= 0) continue;

            try {
                if (!Repository::fixtureHasPendingLegs($fixtureId, $storedProvider)) {
                    error_log('TMR_REPORTING_FIXTURE_SKIP_SETTLED ' . $storedProvider . ':' . $fixtureId);
                    continue;
                }

                $pendingLegs = Repository::pendingLegsForFixture($fixtureId, $storedProvider);
                if ($pendingLegs === []) {
                    continue;
                }

                $loaded = self::loadFixture(
                    $storedProvider,
                    $fixtureId,
                    $pendingLegs,
                    $api,
                    $matcher,
                    $fallbackApi,
                    $fallbackMatcher
                );
                $fixture = is_array($loaded['fixture'] ?? null) ? $loaded['fixture'] : [];
                $settlementProvider = (string)($loaded['provider'] ?? $storedProvider);
                $providerFixtureId = (int)($loaded['fixture_id'] ?? $fixtureId);

                if ($fixture === []) {
                    error_log('TMR_REPORTING_FIXTURE_EMPTY_RETRY ' . $storedProvider . ':' . $fixtureId);
                    Repository::rescheduleFixture($fixtureId, 30, $storedProvider);
                    continue;
                }

                $short = strtoupper(trim((string)($fixture['fixture']['status']['short'] ?? '')));
                $elapsed = self::number($fixture['fixture']['status']['elapsed'] ?? null);

                if (in_array($short, ['PST','TBD','NS','SUSP'], true)) {
                    if ($settlementProvider === 'football_data' && in_array($short, ['TBD','NS'], true)) {
                        // No plano gratuito os resultados podem chegar com atraso.
                        Repository::rescheduleFixture($fixtureId, 60, $storedProvider);
                    } else {
                        Repository::rescheduleFixtureAtNextSweep($fixtureId, $storedProvider);
                    }
                    continue;
                }
                if (in_array($short, ['BT','INT'], true)) {
                    Repository::rescheduleFixture($fixtureId, 30, $storedProvider);
                    continue;
                }
                if (in_array($short, ['1H','HT','2H','ET'], true)) {
                    Repository::rescheduleFixture(
                        $fixtureId,
                        self::postMatchRetryMinutes($short, $elapsed),
                        $storedProvider
                    );
                    continue;
                }
                if (in_array($short, ['CANC','ABD','AWD','WO'], true)) {
                    Repository::markFixtureReview(
                        $fixtureId,
                        'Partida encerrada em status especial: ' . $short,
                        $storedProvider
                    );
                    continue;
                }
                if (!in_array($short, ['FT','AET','PEN'], true)) {
                    error_log('TMR_REPORTING_FIXTURE_STATUS_RETRY ' . $storedProvider . ':' . $fixtureId . ' ' . $short);
                    Repository::rescheduleFixture($fixtureId, 60, $storedProvider);
                    continue;
                }

                $statistics = [];
                if ($settlementProvider === 'api_football' && self::needsStatistics($pendingLegs)) {
                    $statistics = $api->statistics($providerFixtureId);
                }

                $stats = self::buildStats($fixture, $statistics);
                $stats['fixture_status'] = $short;

                $keptPending = false;
                foreach ($pendingLegs as $leg) {
                    if ($settlementProvider === 'football_data' && self::legNeedsStatistics($leg)) {
                        $keptPending = true;
                        error_log('TMR_REPORTING_FALLBACK_STATS_UNAVAILABLE leg=' . (int)$leg['id']);
                        continue;
                    }
                    if ($settlementProvider === 'football_data' && self::legNeedsGoals($leg)
                        && ($stats['goals_home'] === null || $stats['goals_away'] === null)) {
                        $keptPending = true;
                        error_log('TMR_REPORTING_FALLBACK_SCORE_DELAYED leg=' . (int)$leg['id']);
                        continue;
                    }

                    $result = SettlementEngine::settle($leg, $stats);
                    Repository::settleLeg((int)$leg['id'], $result, [
                        'fixture_id'=>$fixtureId,
                        'fixture_provider'=>$storedProvider,
                        'settlement_provider'=>$settlementProvider,
                        'provider_fixture_id'=>$providerFixtureId,
                        'fixture_status'=>$short,
                        'stats'=>$stats,
                    ]);
                }

                if ($keptPending && Repository::fixtureHasPendingLegs($fixtureId, $storedProvider)) {
                    Repository::rescheduleFixture($fixtureId, 60, $storedProvider);
                }
            } catch (\Throwable $e) {
                error_log('TMR_REPORTING_SETTLEMENT_NON_FATAL ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 180));
                Repository::rescheduleFixture($fixtureId, 60, $storedProvider);
            }
        }

        Repository::setState('worker_status', 'ok');
        Repository::setState('worker_last_run_at', gmdate('Y-m-d H:i:s'));
        error_log('TMR_REPORTING_RUN_DONE');
    }

    private static function matchWithFallback(
        array $leg,
        FixtureMatcher $primary,
        ?FixtureMatcher $fallback
    ): array {
        $primaryLookup = null;
        $primaryFailed = false;

        try {
            $primaryLookup = $primary->matchDetailed($leg);
            if (($primaryLookup['status'] ?? '') === 'matched') {
                return ['provider'=>'api_football','lookup'=>$primaryLookup];
            }
        } catch (\Throwable $e) {
            $primaryFailed = true;
            error_log('TMR_REPORTING_PRIMARY_LOOKUP_FAILED ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 120));
        }

        if ($fallback === null) {
            return [
                'provider'=>'api_football',
                'lookup'=>$primaryFailed ? ['status'=>'provider_error','match'=>null] : ($primaryLookup ?? ['status'=>'not_found','match'=>null]),
            ];
        }

        try {
            $fallbackLookup = $fallback->matchDetailed($leg);
            if (($fallbackLookup['status'] ?? '') === 'matched') {
                return ['provider'=>'football_data','lookup'=>$fallbackLookup];
            }
            if ($primaryFailed) {
                // Falha transitória da principal não pode virar "not_found" só
                // porque o free tier do fallback não cobre aquela competição.
                return ['provider'=>'football_data','lookup'=>[
                    'status'=>'provider_error',
                    'match'=>null,
                ]];
            }
        } catch (\Throwable $e) {
            error_log('TMR_REPORTING_FALLBACK_LOOKUP_FAILED ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 120));
            if ($primaryFailed) {
                return ['provider'=>'football_data','lookup'=>['status'=>'provider_error','match'=>null]];
            }
        }

        return ['provider'=>'api_football','lookup'=>$primaryLookup ?? ['status'=>'not_found','match'=>null]];
    }

    private static function loadFixture(
        string $storedProvider,
        int $storedFixtureId,
        array $pendingLegs,
        ApiFootballClient $primaryApi,
        FixtureMatcher $primaryMatcher,
        ?FootballDataClient $fallbackApi,
        ?FixtureMatcher $fallbackMatcher
    ): array {
        $lookupLeg = self::lookupLeg($pendingLegs[0] ?? []);

        if ($storedProvider === 'football_data') {
            // Mesmo quando o evento foi localizado pelo fallback, a API-Football
            // continua tendo prioridade na hora da baixa.
            try {
                $primaryLookup = $primaryMatcher->matchDetailed($lookupLeg);
                if (($primaryLookup['status'] ?? '') === 'matched' && is_array($primaryLookup['match'] ?? null)) {
                    $primaryId = (int)($primaryLookup['match']['fixture_id'] ?? 0);
                    if ($primaryId > 0) {
                        $fixture = $primaryApi->fixture($primaryId);
                        if ($fixture !== []) {
                            error_log('TMR_REPORTING_PRIMARY_RECOVERED fixture=' . $primaryId);
                            return ['provider'=>'api_football','fixture_id'=>$primaryId,'fixture'=>$fixture];
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('TMR_REPORTING_PRIMARY_RECOVERY_FAILED ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 120));
            }

            if ($fallbackApi === null) {
                throw new \RuntimeException('Fallback football-data.org não configurado.');
            }

            $fixture = $fallbackApi->fixture($storedFixtureId);
            return ['provider'=>'football_data','fixture_id'=>$storedFixtureId,'fixture'=>$fixture];
        }

        try {
            $fixture = $primaryApi->fixture($storedFixtureId);
            if ($fixture !== []) {
                return ['provider'=>'api_football','fixture_id'=>$storedFixtureId,'fixture'=>$fixture];
            }
            error_log('TMR_REPORTING_PRIMARY_FIXTURE_EMPTY ' . $storedFixtureId);
        } catch (\Throwable $e) {
            error_log('TMR_REPORTING_PRIMARY_FIXTURE_FAILED ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 120));
        }

        if ($fallbackApi === null || $fallbackMatcher === null) {
            throw new \RuntimeException('API-Football indisponível e fallback não configurado.');
        }

        $fallbackLookup = $fallbackMatcher->matchDetailed($lookupLeg);
        if (($fallbackLookup['status'] ?? '') !== 'matched' || !is_array($fallbackLookup['match'] ?? null)) {
            throw new \RuntimeException('Fallback não localizou a partida com confiança suficiente.');
        }

        $fallbackId = (int)($fallbackLookup['match']['fixture_id'] ?? 0);
        if ($fallbackId <= 0) {
            throw new \RuntimeException('Fallback retornou ID de partida inválido.');
        }

        $fixture = $fallbackApi->fixture($fallbackId);
        if ($fixture === []) {
            throw new \RuntimeException('Fallback retornou partida vazia.');
        }

        error_log('TMR_REPORTING_SETTLEMENT_FALLBACK football_data primary_fixture=' . $storedFixtureId . ' fallback_fixture=' . $fallbackId);
        return ['provider'=>'football_data','fixture_id'=>$fallbackId,'fixture'=>$fixture];
    }

    private static function lookupLeg(array $leg): array
    {
        if (!empty($leg['event_date']) || empty($leg['kickoff_at'])) {
            return $leg;
        }

        try {
            $leg['event_date'] = (new \DateTimeImmutable(
                (string)$leg['kickoff_at'],
                new \DateTimeZone('UTC')
            ))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d');
        } catch (\Throwable) {
        }
        return $leg;
    }

    private static function legNeedsStatistics(array $leg): bool
    {
        return preg_match('/(?:corners|fouls|cards)/', (string)($leg['market_key'] ?? '')) === 1;
    }

    private static function legNeedsGoals(array $leg): bool
    {
        return in_array((string)($leg['market_key'] ?? ''), [
            'match_result',
            'btts',
            'goals_total',
            'team_goals_total',
        ], true);
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
