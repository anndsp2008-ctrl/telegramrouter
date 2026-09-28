<?php declare(strict_types=1);

namespace App\Reporting;

use App\Database;
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
                $status = $marketKey === 'unsupported' ? SettlementEngine::REVIEW : SettlementEngine::PENDING;
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
              AND l.market_key<>'unsupported'
              AND (l.next_check_at IS NULL OR l.next_check_at<=UTC_TIMESTAMP())
            ORDER BY l.id
            LIMIT {$limit}
        ";
        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function rescheduleUnmatchedLeg(int $legId, int $minutes = 60): void
    {
        $minutes = max(15, min(360, $minutes));
        Database::pdo()->exec(
            'UPDATE reporting_legs
             SET next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.(int)$minutes.' MINUTE)
             WHERE id='.(int)$legId.' AND fixture_id IS NULL AND status="PENDING"'
        );
    }

    public static function recordLookupFailure(int $legId, string $reason, int $retryMinutes = 360): int
    {
        $retryMinutes = max(60, min(720, $retryMinutes));
        $reason = mb_substr(trim($reason), 0, 64);
        $stmt = Database::pdo()->prepare(
            'UPDATE reporting_legs
             SET lookup_attempts=lookup_attempts+1,
                 lookup_last_reason=?,
                 next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.(int)$retryMinutes.' MINUTE)
             WHERE id=? AND fixture_id IS NULL AND status="PENDING"'
        );
        $stmt->execute([$reason, $legId]);

        $read = Database::pdo()->prepare('SELECT lookup_attempts FROM reporting_legs WHERE id=?');
        $read->execute([$legId]);
        return (int)$read->fetchColumn();
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
                // Primeira consulta de resultado somente após a janela normal da partida:
                // 90 min de jogo + intervalo + acréscimos/margem operacional.
                // Até esse instante o worker não consulta novamente a API para este fixture.
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
             SET status=?,profit_units=?,settled_at=?
             WHERE id=?'
        );
        $stmt->execute([
            $status,
            $profit,
            $terminal ? gmdate('Y-m-d H:i:s') : null,
            $ticketId,
        ]);
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

    public static function recentTickets(int $limit = 50): array
    {
        Schema::migrate();
        $limit = max(1, min(200, $limit));
        return Database::pdo()->query(
            "SELECT id,destination_chat,bet_kind,total_odds,status,profit_units,placed_at,settled_at
             FROM reporting_tickets
             ORDER BY id DESC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
