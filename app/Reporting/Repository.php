<?php declare(strict_types=1);

namespace App\Reporting;

require_once dirname(__DIR__).'/MatchNameFormatter.php';

use App\Database;
use App\MatchNameFormatter;
use PDO;
use Throwable;

final class Repository
{
    public static function settings(): array
    {
        Schema::migrate();
        $row = Database::pdo()->query('SELECT * FROM reporting_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [
            'enabled'=>0,
            'scope'=>'all',
            'check_results'=>1,
            'daily_report'=>1,
            'report_time'=>'00:00:00',
            'timezone'=>'America/Sao_Paulo',
            'report_chat'=>'',
        ];
    }

    public static function saveSettings(array $input): void
    {
        Schema::migrate();
        $time = preg_match('/^\d{2}:\d{2}$/D', (string)($input['report_time'] ?? ''))
            ? (string)$input['report_time'] . ':00'
            : '00:00:00';
        $timezone = (string)($input['timezone'] ?? 'America/Sao_Paulo');
        try {
            new \DateTimeZone($timezone);
        } catch (Throwable) {
            $timezone = 'America/Sao_Paulo';
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE reporting_settings
             SET enabled=?,scope=?,check_results=?,daily_report=?,report_time=?,timezone=?,report_chat=?
             WHERE id=1'
        );
        $stmt->execute([
            !empty($input['enabled']) ? 1 : 0,
            (($input['scope'] ?? 'all') === 'selected' ? 'selected' : 'all'),
            !empty($input['check_results']) ? 1 : 0,
            !empty($input['daily_report']) ? 1 : 0,
            $time,
            $timezone,
            mb_substr(trim((string)($input['report_chat'] ?? '')), 0, 255),
        ]);
    }

    public static function saveRuleScope(array $ruleIds): void
    {
        Schema::migrate();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM reporting_rule_scope');
            if ($ruleIds !== []) {
                $stmt = $pdo->prepare('INSERT INTO reporting_rule_scope(rule_id,enabled) VALUES(?,1)');
                foreach (array_values(array_unique(array_map('intval', $ruleIds))) as $ruleId) {
                    if ($ruleId > 0) {
                        $stmt->execute([$ruleId]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function selectedRuleIds(): array
    {
        Schema::migrate();
        return array_map(
            'intval',
            Database::pdo()->query('SELECT rule_id FROM reporting_rule_scope WHERE enabled=1 ORDER BY rule_id')
                ->fetchAll(PDO::FETCH_COLUMN) ?: []
        );
    }

    public static function ruleEnabled(int $ruleId): bool
    {
        $settings = self::settings();
        if (empty($settings['enabled'])) {
            return false;
        }
        if (($settings['scope'] ?? 'all') === 'all') {
            return true;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM reporting_rule_scope WHERE rule_id=? AND enabled=1 LIMIT 1'
        );
        $stmt->execute([$ruleId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function captureTicket(array $ticket, array $context): ?int
    {
        Schema::migrate();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO reporting_tickets(
                    rule_id,source_chat,destination_chat,source_message_id,bet_kind,bookmaker,total_odds,
                    stake_units,status,profit_units,placed_at
                 ) VALUES(?,?,?,?,?,?,?,10,"PENDING",0,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
            );
            $stmt->execute([
                (int)($context['rule_id'] ?? 0),
                (string)($context['source_chat'] ?? ''),
                (string)($context['destination_chat'] ?? ''),
                (int)($context['message_id'] ?? 0),
                (string)($ticket['kind'] ?? 'simple'),
                (string)($ticket['bookmaker'] ?? ''),
                $ticket['total_odds'] ?? null,
            ]);

            $ticketId = (int)$pdo->lastInsertId();
            if ($ticketId <= 0) {
                $lookup = $pdo->prepare(
                    'SELECT id FROM reporting_tickets
                     WHERE source_chat=? AND destination_chat=? AND source_message_id=? LIMIT 1'
                );
                $lookup->execute([
                    (string)($context['source_chat'] ?? ''),
                    (string)($context['destination_chat'] ?? ''),
                    (int)($context['message_id'] ?? 0),
                ]);
                $ticketId = (int)$lookup->fetchColumn();
            }
            if ($ticketId <= 0) {
                throw new \RuntimeException('REPORTING_TICKET_ID_MISSING');
            }

            $legInsert = $pdo->prepare(
                'INSERT IGNORE INTO reporting_legs(
                    ticket_id,position_no,sport,match_name,league,event_date,market_text,selection_text,
                    market_key,side,line_value,target_team,odds,status,next_check_at
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
            );

            foreach ((array)($ticket['legs'] ?? []) as $leg) {
                $marketKey = (string)($leg['market_key'] ?? 'unsupported');
                // Toda aposta nasce pendente. Mercados ainda não reconhecidos só entram
                // em revisão após o fluxo de identificação/validação, nunca na captura.
                $status = SettlementEngine::PENDING;
                $legInsert->execute([
                    $ticketId,
                    (int)($leg['position'] ?? 0),
                    (string)($leg['sport'] ?? ''),
                    (string)($leg['match'] ?? ''),
                    (string)($leg['league'] ?? ''),
                    $leg['event_date'] ?? null,
                    (string)($leg['market_text'] ?? ''),
                    (string)($leg['selection_text'] ?? ''),
                    $marketKey,
                    (string)($leg['side'] ?? ''),
                    $leg['line'] ?? null,
                    $leg['target_team'] ?? null,
                    $leg['odds'] ?? null,
                    $status,
                ]);
            }

            $pdo->commit();
            self::recomputeTicket($ticketId);
            return $ticketId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function unmatchedLegs(int $limit = 80): array
    {
        Schema::migrate();
        $limit = max(1, min(200, $limit));
        $sql = "
            SELECT l.*,t.placed_at,t.destination_chat,t.bet_kind,t.total_odds
            FROM reporting_legs l
            JOIN reporting_tickets t ON t.id=l.ticket_id
            WHERE l.status='PENDING'
              AND l.fixture_id IS NULL
              AND (l.next_check_at IS NULL OR l.next_check_at<=UTC_TIMESTAMP())
            ORDER BY l.id
            LIMIT {$limit}
        ";
        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function rescheduleUnmatchedLeg(int $legId, int $minutes = 60): void
    {
        $minutes = max(5, min(180, $minutes));
        Database::pdo()->exec(
            'UPDATE reporting_legs
             SET next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.(int)$minutes.' MINUTE)
             WHERE id='.(int)$legId.' AND fixture_id IS NULL AND status="PENDING"'
        );
    }

    public static function recordLookupFailure(int $legId, string $reason, int $retryMinutes = 15): int
    {
        $reason = mb_substr(trim($reason), 0, 64);
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'UPDATE reporting_legs
             SET lookup_attempts=lookup_attempts+1,
                 lookup_last_reason=?
             WHERE id=? AND fixture_id IS NULL AND status="PENDING"'
        );
        $stmt->execute([$reason, $legId]);

        $read = $pdo->prepare('SELECT lookup_attempts FROM reporting_legs WHERE id=?');
        $read->execute([$legId]);
        $attempts = (int)$read->fetchColumn();
        if ($attempts <= 0) {
            return 0;
        }

        if ($attempts === 1) {
            $minutes = max(5, min(60, $retryMinutes));
            $pdo->exec(
                'UPDATE reporting_legs
                 SET next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.(int)$minutes.' MINUTE)
                 WHERE id='.(int)$legId.' AND fixture_id IS NULL AND status="PENDING"'
            );
        } elseif ($attempts === 2) {
            $pdo->exec(
                'UPDATE reporting_legs
                 SET next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)
                 WHERE id='.(int)$legId.' AND fixture_id IS NULL AND status="PENDING"'
            );
        } else {
            $next = self::nextSparseLookupUtc();
            $nextStmt = $pdo->prepare(
                'UPDATE reporting_legs
                 SET next_check_at=?
                 WHERE id=? AND fixture_id IS NULL AND status="PENDING"'
            );
            $nextStmt->execute([$next, $legId]);
        }

        return $attempts;
    }

    private static function nextSparseLookupUtc(): string
    {
        $localTz = new \DateTimeZone('America/Sao_Paulo');
        $utcTz = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $localTz);
        $candidate = $now->setTime(23, 59, 0);
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+1 day');
        }
        return $candidate->setTimezone($utcTz)->format('Y-m-d H:i:s');
    }

    public static function requeuePendingUnmatchedForRetryPolicyOnce(): int
    {
        Schema::migrate();
        $stateKey = 'unmatched_retry_policy_v3';

        $check = Database::pdo()->prepare(
            'SELECT state_value FROM reporting_runtime_state WHERE state_key=? LIMIT 1'
        );
        $check->execute([$stateKey]);
        if ((string)$check->fetchColumn() === 'done') {
            return 0;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'UPDATE reporting_legs
             SET next_check_at=UTC_TIMESTAMP(),
                 lookup_attempts=0,
                 lookup_last_reason="retry_policy_requeue"
             WHERE status="PENDING"
               AND fixture_id IS NULL'
        );
        $stmt->execute();
        $count = $stmt->rowCount();

        Schema::setState($stateKey, 'done');
        return $count;
    }

    public static function restoreUnicodeDashLookupFailures(): int
    {
        Schema::migrate();
        $pdo = Database::pdo();
        $rows = $pdo->query(
            'SELECT id,ticket_id
             FROM reporting_legs
             WHERE fixture_id IS NULL
               AND (
                    match_name LIKE "%–%"
                    OR match_name LIKE "%—%"
                    OR match_name LIKE "%−%"
               )
               AND (
                    (status="PENDING" AND lookup_last_reason="invalid_match_name")
                    OR (
                        status="REVIEW"
                        AND JSON_UNQUOTE(JSON_EXTRACT(settlement_details,"$.reason"))=
                            "Nome da partida insuficiente para identificar o evento após duas tentativas."
                    )
               )'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows === []) {
            return 0;
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $ticketIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['ticket_id'],
            $rows
        )));

        $pdo->exec(
            'UPDATE reporting_legs
             SET status="PENDING",
                 settlement_details=NULL,
                 settled_at=NULL,
                 next_check_at=UTC_TIMESTAMP(),
                 lookup_attempts=0,
                 lookup_last_reason="unicode_separator_reopen"
             WHERE id IN (' . implode(',', $ids) . ')'
        );

        foreach ($ticketIds as $ticketId) {
            if ($ticketId > 0) {
                self::recomputeTicket($ticketId);
            }
        }

        return count($ids);
    }

    public static function restoreImmediateUnsupportedReviews(): int
    {
        Schema::migrate();
        $pdo = Database::pdo();
        $rows = $pdo->query(
            'SELECT id,ticket_id
             FROM reporting_legs
             WHERE status="REVIEW"
               AND fixture_id IS NULL
               AND market_key="unsupported"
               AND (settlement_details IS NULL OR settlement_details="null")'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows === []) {
            return 0;
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $ticketIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['ticket_id'], $rows)));

        $pdo->exec(
            'UPDATE reporting_legs
             SET status="PENDING",
                 settled_at=NULL,
                 next_check_at=UTC_TIMESTAMP(),
                 lookup_attempts=0,
                 lookup_last_reason="legacy_immediate_review"
             WHERE id IN (' . implode(',', $ids) . ')'
        );

        foreach ($ticketIds as $ticketId) {
            if ($ticketId > 0) {
                self::recomputeTicket($ticketId);
            }
        }

        return count($ids);
    }

    public static function reopenLegacyConfidenceReviewsOnce(): int
    {
        Schema::migrate();
        $stateKey = 'team_id_matcher_review_reopen_v1';
        $check = Database::pdo()->prepare(
            'SELECT state_value FROM reporting_runtime_state WHERE state_key=? LIMIT 1'
        );
        $check->execute([$stateKey]);
        if ((string)$check->fetchColumn() === 'done') {
            return 0;
        }

        $pdo = Database::pdo();
        $rows = $pdo->query(
            'SELECT id,ticket_id
             FROM reporting_legs
             WHERE status="REVIEW"
               AND fixture_id IS NULL
               AND (
                 JSON_UNQUOTE(JSON_EXTRACT(settlement_details,"$.reason"))=
                   "Partida encontrada com confiança insuficiente após duas tentativas espaçadas."
                 OR JSON_UNQUOTE(JSON_EXTRACT(settlement_details,"$.reason"))=
                   "Partida/horário oficial não identificado após duas consultas espaçadas."
               )'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows !== []) {
            $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
            $ticketIds = array_values(array_unique(array_map(
                static fn(array $row): int => (int)$row['ticket_id'],
                $rows
            )));

            $pdo->exec(
                'UPDATE reporting_legs
                 SET status="PENDING",
                     settlement_details=NULL,
                     settled_at=NULL,
                     next_check_at=UTC_TIMESTAMP(),
                     lookup_attempts=0,
                     lookup_last_reason="team_id_matcher_reopen"
                 WHERE id IN (' . implode(',', $ids) . ')'
            );

            foreach ($ticketIds as $ticketId) {
                if ($ticketId > 0) {
                    self::recomputeTicket($ticketId);
                }
            }
        }

        Schema::setState($stateKey, 'done');
        return count($rows);
    }

    public static function restorePrematureLookupReviews(): int
    {
        Schema::migrate();
        $pdo = Database::pdo();
        $rows = $pdo->query(
            'SELECT id,ticket_id
             FROM reporting_legs
             WHERE status="REVIEW"
               AND fixture_id IS NULL
               AND market_key<>"unsupported"
               AND JSON_UNQUOTE(JSON_EXTRACT(settlement_details,"$.reason"))=
                   "Partida/horário oficial não identificado com confiança na consulta inicial."'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows === []) {
            return 0;
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $ticketIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['ticket_id'], $rows)));
        $pdo->exec(
            'UPDATE reporting_legs
             SET status="PENDING",
                 settlement_details=NULL,
                 settled_at=NULL,
                 next_check_at=UTC_TIMESTAMP(),
                 lookup_attempts=0,
                 lookup_last_reason="policy_reopened"
             WHERE id IN (' . implode(',', $ids) . ')'
        );

        foreach ($ticketIds as $ticketId) {
            if ($ticketId > 0) {
                self::recomputeTicket($ticketId);
            }
        }
        return count($ids);
    }

    public static function attachFixture(int $legId, array $match): void
    {
        $kickoff = (string)($match['kickoff_at'] ?? '');
        $next = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($kickoff !== '') {
            try {
                // Sparse API policy: depois de identificar o fixture/horário oficial,
                // não existe polling durante a partida. A próxima chamada fica para
                // 15 min após o término estimado (90 min + intervalo/acréscimos = 110;
                // margem pós-jogo = 15; total = kickoff + 125 min).
                $candidate = (new \DateTimeImmutable($kickoff, new \DateTimeZone('UTC')))->modify('+125 minutes');
                if ($candidate > $next) {
                    $next = $candidate;
                }
            } catch (Throwable) {
            }
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE reporting_legs
             SET fixture_id=?,api_home_team=?,api_away_team=?,kickoff_at=?,match_confidence=?,next_check_at=?,
                 lookup_last_reason=NULL
             WHERE id=? AND fixture_id IS NULL AND status="PENDING"'
        );
        $stmt->execute([
            (int)$match['fixture_id'],
            (string)($match['home_team'] ?? ''),
            (string)($match['away_team'] ?? ''),
            $kickoff !== '' ? $kickoff : null,
            (float)($match['confidence'] ?? 0),
            $next->format('Y-m-d H:i:s'),
            $legId,
        ]);
    }

    public static function deferPendingFixtureChecksToPostMatchWindow(): void
    {
        Database::pdo()->exec(
            'UPDATE reporting_legs
             SET next_check_at=DATE_ADD(kickoff_at, INTERVAL 125 MINUTE)
             WHERE status="PENDING"
               AND fixture_id IS NOT NULL
               AND kickoff_at IS NOT NULL
               AND (
                    next_check_at IS NULL
                    OR next_check_at<DATE_ADD(kickoff_at, INTERVAL 125 MINUTE)
               )'
        );
    }

    public static function dueFixtureIds(int $limit = 20): array
    {
        Schema::migrate();
        $limit = max(1, min(100, $limit));
        $sql = "
            SELECT DISTINCT fixture_id
            FROM reporting_legs
            WHERE status='PENDING'
              AND fixture_id IS NOT NULL
              AND (next_check_at IS NULL OR next_check_at<=UTC_TIMESTAMP())
            ORDER BY fixture_id
            LIMIT {$limit}
        ";
        return array_map('intval', Database::pdo()->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public static function fixtureHasPendingLegs(int $fixtureId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1
             FROM reporting_legs
             WHERE fixture_id=? AND status="PENDING"
             LIMIT 1'
        );
        $stmt->execute([$fixtureId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function pendingLegsForFixture(int $fixtureId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.*,t.bet_kind,t.total_odds,t.stake_units
             FROM reporting_legs l
             JOIN reporting_tickets t ON t.id=l.ticket_id
             WHERE l.fixture_id=? AND l.status="PENDING"
             ORDER BY l.position_no'
        );
        $stmt->execute([$fixtureId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function rescheduleFixture(int $fixtureId, int $minutes): void
    {
        $minutes = max(5, min(180, $minutes));
        Database::pdo()->exec(
            'UPDATE reporting_legs
             SET next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.(int)$minutes.' MINUTE)
             WHERE fixture_id='.(int)$fixtureId.' AND status="PENDING"'
        );
    }

    public static function rescheduleFixtureAtNextSweep(int $fixtureId): void
    {
        $next = self::nextSparseLookupUtc();
        $stmt = Database::pdo()->prepare(
            'UPDATE reporting_legs
             SET next_check_at=?
             WHERE fixture_id=? AND status="PENDING"'
        );
        $stmt->execute([$next, $fixtureId]);
    }

    public static function settleLeg(int $legId, array $result, array $details): void
    {
        $payload = $details;
        $payload['return_factor'] = $result['return_factor'] ?? null;
        $payload['observed'] = $result['observed'] ?? null;
        if (!empty($result['reason'])) {
            $payload['reason'] = (string)$result['reason'];
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE reporting_legs
             SET status=?,settlement_details=?,settled_at=UTC_TIMESTAMP(),next_check_at=NULL
             WHERE id=? AND status="PENDING"'
        );
        $stmt->execute([
            (string)$result['status'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $legId,
        ]);

        $ticketId = (int)Database::pdo()->query(
            'SELECT ticket_id FROM reporting_legs WHERE id=' . (int)$legId
        )->fetchColumn();
        if ($ticketId > 0) {
            self::recomputeTicket($ticketId);
        }
    }

    public static function markFixtureReview(int $fixtureId, string $reason): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE reporting_legs
             SET status="REVIEW",settlement_details=?,settled_at=UTC_TIMESTAMP(),next_check_at=NULL
             WHERE fixture_id=? AND status="PENDING"'
        );
        $stmt->execute([
            json_encode(['reason'=>$reason], JSON_UNESCAPED_UNICODE),
            $fixtureId,
        ]);

        $tickets = Database::pdo()->prepare(
            'SELECT DISTINCT ticket_id FROM reporting_legs WHERE fixture_id=?'
        );
        $tickets->execute([$fixtureId]);
        foreach ($tickets->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ticketId) {
            self::recomputeTicket((int)$ticketId);
        }
    }

    public static function recomputeTicket(int $ticketId): void
    {
        $pdo = Database::pdo();
        $ticketStmt = $pdo->prepare('SELECT * FROM reporting_tickets WHERE id=?');
        $ticketStmt->execute([$ticketId]);
        $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ticket)) {
            return;
        }

        $legsStmt = $pdo->prepare('SELECT * FROM reporting_legs WHERE ticket_id=? ORDER BY position_no');
        $legsStmt->execute([$ticketId]);
        $legs = $legsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($legs === []) {
            return;
        }

        $stake = (float)$ticket['stake_units'];
        $totalOdds = is_numeric($ticket['total_odds']) ? (float)$ticket['total_odds'] : null;

        $manualOverride = strtoupper(trim((string)($ticket['manual_status_override'] ?? '')));
        if (in_array($manualOverride, [
            SettlementEngine::GREEN,
            SettlementEngine::RED,
            SettlementEngine::VOID,
            SettlementEngine::HALF_GREEN,
            SettlementEngine::HALF_RED,
            SettlementEngine::REVIEW,
        ], true)) {
            $manualProfit = self::manualTicketProfit($manualOverride, $stake, $totalOdds);
            $manualUpdate = $pdo->prepare(
                'UPDATE reporting_tickets
                 SET status=?,
                     profit_units=?,
                     settled_at=CASE WHEN ?="REVIEW" THEN NULL ELSE COALESCE(settled_at,UTC_TIMESTAMP()) END
                 WHERE id=?'
            );
            $manualUpdate->execute([$manualOverride, $manualProfit, $manualOverride, $ticketId]);
            return;
        }

        $kind = (string)$ticket['bet_kind'];
        $statuses = array_map(static fn(array $leg): string => (string)$leg['status'], $legs);

        $status = SettlementEngine::PENDING;
        $profit = 0.0;

        if (count($legs) === 1 || $kind === 'simple') {
            $legStatus = $statuses[0];
            [$status, $profit] = self::singleFinancialResult($legStatus, $stake, $totalOdds);
        } elseif (in_array(SettlementEngine::RED, $statuses, true)) {
            $status = SettlementEngine::RED;
            $profit = -$stake;
        } elseif (in_array(SettlementEngine::PENDING, $statuses, true)) {
            $status = SettlementEngine::PENDING;
        } elseif (in_array(SettlementEngine::REVIEW, $statuses, true)) {
            $status = SettlementEngine::REVIEW;
        } elseif (count(array_filter($statuses, static fn(string $s): bool => $s === SettlementEngine::GREEN)) === count($statuses)) {
            if ($totalOdds !== null && $totalOdds > 1) {
                $status = SettlementEngine::GREEN;
                $profit = round($stake * ($totalOdds - 1), 4);
            } else {
                $status = SettlementEngine::REVIEW;
            }
        } else {
            $factor = 1.0;
            $canCalculate = true;
            foreach ($legs as $leg) {
                $legOdds = is_numeric($leg['odds']) ? (float)$leg['odds'] : null;
                $legStatus = (string)$leg['status'];
                $legFactor = match ($legStatus) {
                    SettlementEngine::GREEN => $legOdds !== null && $legOdds > 1 ? $legOdds : null,
                    SettlementEngine::VOID => 1.0,
                    SettlementEngine::HALF_GREEN => $legOdds !== null && $legOdds > 1 ? 1 + (($legOdds - 1) / 2) : null,
                    SettlementEngine::HALF_RED => 0.5,
                    default => null,
                };
                if ($legFactor === null) {
                    $canCalculate = false;
                    break;
                }
                $factor *= $legFactor;
            }

            if (!$canCalculate) {
                $status = SettlementEngine::REVIEW;
            } else {
                $profit = round($stake * ($factor - 1), 4);
                if ($profit > 0.0001) {
                    $status = SettlementEngine::GREEN;
                } elseif ($profit < -0.0001) {
                    $status = SettlementEngine::RED;
                } else {
                    $status = SettlementEngine::VOID;
                }
            }
        }

        $terminal = $status !== SettlementEngine::PENDING;
        $stmt = $pdo->prepare(
            'UPDATE reporting_tickets
             SET status=?,
                 profit_units=?,
                 settled_at=?
             WHERE id=?'
        );
        $stmt->execute([
            $status,
            $profit,
            $terminal ? gmdate('Y-m-d H:i:s') : null,
            $ticketId,
        ]);
    }

    private static function manualTicketProfit(string $status, float $stake, ?float $odds): float
    {
        return match ($status) {
            SettlementEngine::GREEN => $odds !== null && $odds > 1
                ? round($stake * ($odds - 1), 4)
                : 0.0,
            SettlementEngine::RED => -$stake,
            SettlementEngine::HALF_GREEN => $odds !== null && $odds > 1
                ? round(($stake / 2) * ($odds - 1), 4)
                : 0.0,
            SettlementEngine::HALF_RED => -round($stake / 2, 4),
            default => 0.0,
        };
    }

    private static function singleFinancialResult(string $status, float $stake, ?float $odds): array
    {
        return match ($status) {
            SettlementEngine::GREEN => $odds !== null && $odds > 1
                ? [SettlementEngine::GREEN, round($stake * ($odds - 1), 4)]
                : [SettlementEngine::REVIEW, 0.0],
            SettlementEngine::RED => [SettlementEngine::RED, -$stake],
            SettlementEngine::VOID => [SettlementEngine::VOID, 0.0],
            SettlementEngine::HALF_GREEN => $odds !== null && $odds > 1
                ? [SettlementEngine::HALF_GREEN, round(($stake / 2) * ($odds - 1), 4)]
                : [SettlementEngine::REVIEW, 0.0],
            SettlementEngine::HALF_RED => [SettlementEngine::HALF_RED, -round($stake / 2, 4)],
            SettlementEngine::REVIEW => [SettlementEngine::REVIEW, 0.0],
            default => [SettlementEngine::PENDING, 0.0],
        };
    }

    public static function destinationsForDate(string $date, string $timezone): array
    {
        [$start, $end] = self::utcWindow($date, $timezone);
        $stmt = Database::pdo()->prepare(
            'SELECT DISTINCT destination_chat
             FROM reporting_tickets
             WHERE placed_at>=? AND placed_at<? AND destination_chat<>""
             ORDER BY destination_chat'
        );
        $stmt->execute([$start, $end]);
        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    }

    public static function reportStats(string $date, string $timezone, ?string $destinationChat = null): array
    {
        [$start, $end] = self::utcWindow($date, $timezone);
        $sql = '
            SELECT
                COUNT(*) total,
                SUM(status="GREEN") greens,
                SUM(status="RED") reds,
                SUM(status="VOID") voids,
                SUM(status="HALF_GREEN") half_greens,
                SUM(status="HALF_RED") half_reds,
                SUM(status="PENDING") pending,
                SUM(status="REVIEW") review,
                COALESCE(SUM(profit_units),0) profit,
                COALESCE(AVG(total_odds),0) avg_odds,
                COALESCE(SUM(CASE WHEN status<>"PENDING" AND status<>"REVIEW" THEN stake_units ELSE 0 END),0) settled_stake
            FROM reporting_tickets
            WHERE placed_at>=? AND placed_at<?';
        $params = [$start, $end];
        if ($destinationChat !== null) {
            $sql .= ' AND destination_chat=?';
            $params[] = $destinationChat;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public static function cumulativeProfit(string $date, string $timezone, ?string $destinationChat = null): float
    {
        [, $end] = self::utcWindow($date, $timezone);
        $sql = '
            SELECT COALESCE(SUM(profit_units),0)
            FROM reporting_tickets
            WHERE placed_at<?';
        $params = [$end];
        if ($destinationChat !== null) {
            $sql .= ' AND destination_chat=?';
            $params[] = $destinationChat;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }

    public static function enqueueDailyReport(string $date, string $destinationChat, string $payload): bool
    {
        Schema::migrate();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $reportStmt = $pdo->prepare(
                'INSERT INTO reporting_daily_reports(report_date,destination_chat,status,payload,generated_at)
                 VALUES(?,?,"QUEUED",?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
            );
            $reportStmt->execute([$date, $destinationChat, $payload]);
            $reportId = (int)$pdo->lastInsertId();
            if ($reportId <= 0) {
                $find = $pdo->prepare(
                    'SELECT id FROM reporting_daily_reports WHERE report_date=? AND destination_chat=?'
                );
                $find->execute([$date, $destinationChat]);
                $reportId = (int)$find->fetchColumn();
            }

            $dedupe = 'daily:' . $date . ':' . hash('sha256', $destinationChat);
            $randomId = self::randomId($dedupe);
            $outbox = $pdo->prepare(
                'INSERT IGNORE INTO reporting_outbox(
                    kind,dedupe_key,destination_chat,payload,report_id,telegram_random_id,status,available_at
                 ) VALUES("DAILY_REPORT",?,?,?,?,?,"PENDING",UTC_TIMESTAMP())'
            );
            $outbox->execute([$dedupe, $destinationChat, $payload, $reportId, $randomId]);
            $created = $outbox->rowCount() === 1;
            $pdo->commit();
            return $created;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function claimOutbox(): ?array
    {
        Schema::migrate();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec(
                'UPDATE reporting_outbox
                 SET status="PENDING",locked_at=NULL
                 WHERE status="SENDING" AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)'
            );
            $row = $pdo->query(
                'SELECT * FROM reporting_outbox
                 WHERE status="PENDING" AND available_at<=UTC_TIMESTAMP()
                 ORDER BY id
                 LIMIT 1
                 FOR UPDATE'
            )->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                $pdo->commit();
                return null;
            }

            $stmt = $pdo->prepare(
                'UPDATE reporting_outbox
                 SET status="SENDING",attempts=attempts+1,locked_at=UTC_TIMESTAMP()
                 WHERE id=? AND status="PENDING"'
            );
            $stmt->execute([(int)$row['id']]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $pdo->commit();
            $row['attempts'] = (int)$row['attempts'] + 1;
            return $row;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function markOutboxSent(int $id, ?int $telegramMessageId): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE reporting_outbox
                 SET status="SENT",sent_at=UTC_TIMESTAMP(),telegram_message_id=?,locked_at=NULL,last_error=NULL
                 WHERE id=?'
            );
            $stmt->execute([$telegramMessageId, $id]);

            $report = $pdo->prepare(
                'UPDATE reporting_daily_reports r
                 JOIN reporting_outbox o ON o.report_id=r.id
                 SET r.status="SENT",r.sent_at=UTC_TIMESTAMP(),r.telegram_message_id=?
                 WHERE o.id=?'
            );
            $report->execute([$telegramMessageId, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function markOutboxFailed(int $id, string $error): void
    {
        $error = mb_substr(preg_replace('/\s+/', ' ', trim($error)) ?? '', 0, 500);
        $stmt = Database::pdo()->prepare(
            'UPDATE reporting_outbox
             SET status="PENDING",available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL LEAST(300,30*attempts) SECOND),
                 locked_at=NULL,last_error=?
             WHERE id=?'
        );
        $stmt->execute([$error, $id]);
    }

    public static function reviewLegs(int $limit = 50): array
    {
        Schema::migrate();
        $limit = max(1, min(200, $limit));
        $sql = "
            SELECT
                l.id,l.ticket_id,l.position_no,l.fixture_id,l.match_name,l.league,l.event_date,
                l.kickoff_at,l.market_text,l.selection_text,l.odds,l.status,l.settlement_details,
                t.destination_chat,t.bet_kind,t.total_odds,t.stake_units,t.placed_at
            FROM reporting_legs l
            JOIN reporting_tickets t ON t.id=l.ticket_id
            WHERE l.status='REVIEW'
            ORDER BY l.id DESC
            LIMIT {$limit}
        ";
        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function manualSetTicketStatus(int $ticketId, string $status): void
    {
        Schema::migrate();

        $status = strtoupper(trim($status));
        $allowed = [
            SettlementEngine::PENDING,
            SettlementEngine::GREEN,
            SettlementEngine::RED,
            SettlementEngine::VOID,
            SettlementEngine::HALF_GREEN,
            SettlementEngine::HALF_RED,
            SettlementEngine::REVIEW,
        ];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Status manual inválido.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $ticketStmt = $pdo->prepare(
                'SELECT id,stake_units,total_odds
                 FROM reporting_tickets
                 WHERE id=?
                 FOR UPDATE'
            );
            $ticketStmt->execute([$ticketId]);
            $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($ticket)) {
                throw new \RuntimeException('Aposta não encontrada.');
            }

            $stake = (float)($ticket['stake_units'] ?? 0);
            $odds = is_numeric($ticket['total_odds'] ?? null)
                ? (float)$ticket['total_odds']
                : null;
            $profit = self::manualTicketProfit($status, $stake, $odds);
            $now = gmdate('Y-m-d H:i:s');

            if ($status === SettlementEngine::PENDING) {
                // Reabrir como PENDING é a única ação manual que altera as legs:
                // devolve o bilhete explicitamente para a fila automática.
                $legs = $pdo->prepare(
                    'UPDATE reporting_legs
                     SET status="PENDING",
                         settlement_details=NULL,
                         settled_at=NULL,
                         next_check_at=UTC_TIMESTAMP(),
                         lookup_attempts=0,
                         lookup_last_reason="manual_status_pending"
                     WHERE ticket_id=?'
                );
                $legs->execute([$ticketId]);

                $ticketUpdate = $pdo->prepare(
                    'UPDATE reporting_tickets
                     SET status="PENDING",
                         profit_units=0,
                         settled_at=NULL,
                         manual_status_override=NULL,
                         manual_status_updated_at=?
                     WHERE id=?'
                );
                $ticketUpdate->execute([$now, $ticketId]);
            } else {
                // Qualquer status manual diferente de PENDING encerra a aposta
                // para a fila automática. A API-Football só volta a consultar
                // este ticket se o usuário reabrir explicitamente como PENDING.
                $details = json_encode([
                    'manual'=>true,
                    'manual_ticket_status'=>$status,
                    'updated_at'=>gmdate('c'),
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

                $legs = $pdo->prepare(
                    'UPDATE reporting_legs
                     SET status=?,
                         settlement_details=?,
                         settled_at=UTC_TIMESTAMP(),
                         next_check_at=NULL,
                         lookup_attempts=0,
                         lookup_last_reason="manual_ticket_status"
                     WHERE ticket_id=?'
                );
                $legs->execute([$status, $details, $ticketId]);

                $ticketUpdate = $pdo->prepare(
                    'UPDATE reporting_tickets
                     SET status=?,
                         profit_units=?,
                         settled_at=?,
                         manual_status_override=?,
                         manual_status_updated_at=?
                     WHERE id=?'
                );
                $ticketUpdate->execute([
                    $status,
                    $profit,
                    $status === SettlementEngine::REVIEW ? null : $now,
                    $status,
                    $now,
                    $ticketId,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function manualResolveLeg(int $legId, string $status): void
    {
        Schema::migrate();
        $allowed = [
            SettlementEngine::GREEN,
            SettlementEngine::RED,
            SettlementEngine::VOID,
            SettlementEngine::HALF_GREEN,
            SettlementEngine::HALF_RED,
        ];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Status manual inválido.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT ticket_id FROM reporting_legs WHERE id=? AND status="REVIEW" FOR UPDATE'
            );
            $stmt->execute([$legId]);
            $ticketId = (int)$stmt->fetchColumn();
            if ($ticketId <= 0) {
                throw new \RuntimeException('Seleção em revisão não encontrada.');
            }

            $details = json_encode([
                'manual'=>true,
                'manual_status'=>$status,
                'resolved_at'=>gmdate('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            $update = $pdo->prepare(
                'UPDATE reporting_legs
                 SET status=?,settlement_details=?,settled_at=UTC_TIMESTAMP(),next_check_at=NULL
                 WHERE id=? AND status="REVIEW"'
            );
            $update->execute([$status, $details, $legId]);
            $pdo->commit();

            self::recomputeTicket($ticketId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function reprocessReviewLeg(int $legId): void
    {
        Schema::migrate();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT ticket_id,fixture_id,kickoff_at
                 FROM reporting_legs
                 WHERE id=? AND status="REVIEW"
                 FOR UPDATE'
            );
            $stmt->execute([$legId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \RuntimeException('Seleção em revisão não encontrada.');
            }

            $nextCheck = gmdate('Y-m-d H:i:s');
            if (!empty($row['fixture_id']) && !empty($row['kickoff_at'])) {
                try {
                    $candidate = (new \DateTimeImmutable((string)$row['kickoff_at'], new \DateTimeZone('UTC')))
                        ->modify('+125 minutes');
                    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    if ($candidate > $now) {
                        $nextCheck = $candidate->format('Y-m-d H:i:s');
                    }
                } catch (Throwable) {
                }
            }

            $update = $pdo->prepare(
                'UPDATE reporting_legs
                 SET status="PENDING",
                     settlement_details=NULL,
                     settled_at=NULL,
                     next_check_at=?,
                     lookup_attempts=0,
                     lookup_last_reason="manual_reprocess"
                 WHERE id=? AND status="REVIEW"'
            );
            $update->execute([$nextCheck, $legId]);
            $pdo->commit();

            self::recomputeTicket((int)$row['ticket_id']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function findTeamAlias(string $alias): ?array
    {
        Schema::migrate();
        $key = self::teamAliasKey($alias);
        if ($key === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT alias_text,team_id,api_name,country,confidence
             FROM reporting_team_aliases
             WHERE alias_key=?
             LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function saveTeamAlias(
        string $alias,
        int $teamId,
        string $apiName,
        string $country = '',
        float $confidence = 1.0
    ): void {
        Schema::migrate();
        $key = self::teamAliasKey($alias);
        if ($key === '' || $teamId <= 0 || trim($apiName) === '') {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO reporting_team_aliases(alias_key,alias_text,team_id,api_name,country,confidence)
             VALUES(?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               alias_text=VALUES(alias_text),
               team_id=VALUES(team_id),
               api_name=VALUES(api_name),
               country=VALUES(country),
               confidence=GREATEST(confidence,VALUES(confidence))'
        );
        $stmt->execute([
            $key,
            mb_substr(trim($alias), 0, 190),
            $teamId,
            mb_substr(trim($apiName), 0, 190),
            mb_substr(trim($country), 0, 120),
            max(0.0, min(1.0, $confidence)),
        ]);
    }

    private static function teamAliasKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($ascii) ? strtolower($ascii) : $value;
        return mb_substr(preg_replace('/[^a-z0-9]+/', '', $value) ?? '', 0, 190);
    }

    public static function recentTickets(int $limit = 50): array
    {
        Schema::migrate();
        $limit = max(1, min(200, $limit));

        $pdo = Database::pdo();
        $tickets = $pdo->query(
            "SELECT id,source_chat,destination_chat,bet_kind,total_odds,status,profit_units,placed_at,settled_at
             FROM reporting_tickets
             ORDER BY id DESC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($tickets === []) {
            return [];
        }

        $ticketIds = array_map(
            static fn(array $ticket): int => (int)$ticket['id'],
            $tickets
        );
        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));

        $stmt = $pdo->prepare(
            "SELECT ticket_id,position_no,match_name,market_text,selection_text,odds,status
             FROM reporting_legs
             WHERE ticket_id IN ({$placeholders})
             ORDER BY ticket_id DESC, position_no ASC"
        );
        $stmt->execute($ticketIds);

        $legsByTicket = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $leg) {
            $legsByTicket[(int)$leg['ticket_id']][] = $leg;
        }

        foreach ($tickets as &$ticket) {
            $ticket['legs'] = $legsByTicket[(int)$ticket['id']] ?? [];
        }
        unset($ticket);

        return $tickets;
    }

    public static function normalizeStoredMatchNamesOnce(): int
    {
        Schema::migrate();
        $stateKey = 'global_match_vs_v1';

        $check = Database::pdo()->prepare(
            'SELECT state_value FROM reporting_runtime_state WHERE state_key=? LIMIT 1'
        );
        $check->execute([$stateKey]);
        if ((string)$check->fetchColumn() === 'done') {
            return 0;
        }

        $pdo = Database::pdo();
        $rows = $pdo->query(
            'SELECT id,match_name FROM reporting_legs ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $update = $pdo->prepare(
            'UPDATE reporting_legs SET match_name=? WHERE id=?'
        );
        $changed = 0;

        foreach ($rows as $row) {
            $current = (string)($row['match_name'] ?? '');
            $normalized = MatchNameFormatter::normalize($current);
            if ($normalized === '' || $normalized === $current) {
                continue;
            }
            $update->execute([$normalized, (int)$row['id']]);
            $changed += $update->rowCount();
        }

        Schema::setState($stateKey, 'done');
        return $changed;
    }

    public static function forcePendingChecksOnce(string $stateKey): int
    {
        Schema::migrate();
        $stateKey = trim($stateKey);
        if ($stateKey === '') {
            throw new \InvalidArgumentException('stateKey vazio');
        }

        $check = Database::pdo()->prepare(
            'SELECT state_value FROM reporting_runtime_state WHERE state_key=? LIMIT 1'
        );
        $check->execute([$stateKey]);
        if ((string)$check->fetchColumn() === 'done') {
            return 0;
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE reporting_legs
             SET next_check_at=UTC_TIMESTAMP()
             WHERE status="PENDING"'
        );
        $stmt->execute();
        $count = $stmt->rowCount();

        Schema::setState($stateKey, 'done');
        return $count;
    }

    public static function requestManualPendingCheck(): int
    {
        Schema::migrate();
        $pdo = Database::pdo();

        $count = (int)$pdo->query(
            'SELECT COUNT(DISTINCT ticket_id)
             FROM reporting_legs
             WHERE status="PENDING"'
        )->fetchColumn();

        if ($count <= 0) {
            return 0;
        }

        $pdo->exec(
            'UPDATE reporting_legs
             SET next_check_at=UTC_TIMESTAMP()
             WHERE status="PENDING"'
        );

        Schema::setState('manual_pending_check_requested_at', gmdate('Y-m-d H:i:s'));
        Schema::setState('manual_pending_check_status', 'queued');
        Schema::setState('manual_pending_check_count', (string)$count);

        return $count;
    }

    public static function consumeManualPendingCheckRequest(): bool
    {
        Schema::migrate();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT state_value
             FROM reporting_runtime_state
             WHERE state_key="manual_pending_check_requested_at"
             LIMIT 1'
        );
        $stmt->execute();
        $requestedAt = trim((string)$stmt->fetchColumn());

        if ($requestedAt === '') {
            return false;
        }

        Schema::setState('manual_pending_check_requested_at', null);
        Schema::setState('manual_pending_check_status', 'running');
        return true;
    }

    public static function pendingQueueSummary(): array
    {
        Schema::migrate();
        $row = Database::pdo()->query(
            "SELECT
                SUM(status='PENDING') AS pending_total,
                SUM(status='PENDING' AND fixture_id IS NULL) AS pending_without_fixture,
                SUM(status='PENDING' AND fixture_id IS NOT NULL) AS pending_with_fixture,
                SUM(status='PENDING' AND fixture_id IS NULL AND (next_check_at IS NULL OR next_check_at<=UTC_TIMESTAMP())) AS due_without_fixture,
                SUM(status='PENDING' AND fixture_id IS NOT NULL AND (next_check_at IS NULL OR next_check_at<=UTC_TIMESTAMP())) AS due_with_fixture,
                MIN(CASE WHEN status='PENDING' AND fixture_id IS NULL THEN next_check_at END) AS next_unmatched_check_at,
                MIN(CASE WHEN status='PENDING' AND fixture_id IS NOT NULL THEN next_check_at END) AS next_fixture_check_at,
                MIN(CASE WHEN status='PENDING' THEN kickoff_at END) AS oldest_pending_kickoff_at,
                MAX(CASE WHEN status='PENDING' THEN kickoff_at END) AS latest_pending_kickoff_at
             FROM reporting_legs"
        )->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [];
        }

        foreach ([
            'pending_total',
            'pending_without_fixture',
            'pending_with_fixture',
            'due_without_fixture',
            'due_with_fixture',
        ] as $key) {
            $row[$key] = (int)($row[$key] ?? 0);
        }

        return $row;
    }

    public static function setState(string $key, ?string $value): void
    {
        Schema::setState($key, $value);
    }

    private static function utcWindow(string $date, string $timezone): array
    {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (Throwable) {
            $tz = new \DateTimeZone('America/Sao_Paulo');
        }
        $startLocal = new \DateTimeImmutable($date . ' 00:00:00', $tz);
        $endLocal = $startLocal->modify('+1 day');
        $utc = new \DateTimeZone('UTC');
        return [
            $startLocal->setTimezone($utc)->format('Y-m-d H:i:s'),
            $endLocal->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    private static function randomId(string $seed): int
    {
        $hex = substr(hash('sha256', $seed), 0, 15);
        $value = (int)hexdec($hex);
        return max(1, $value);
    }
}
