<?php declare(strict_types=1);

namespace App\Reporting;

use App\Database;
use PDO;

final class Schema
{
    public const VERSION = 1;
    private static bool $migrated = false;

    public static function migrate(): void
    {
        if (self::$migrated) {
            return;
        }
        $pdo = Database::pdo();

        self::dropLegacy($pdo);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reporting_settings (
                id TINYINT UNSIGNED PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                scope VARCHAR(16) NOT NULL DEFAULT 'all',
                check_results TINYINT(1) NOT NULL DEFAULT 1,
                daily_report TINYINT(1) NOT NULL DEFAULT 1,
                report_time TIME NOT NULL DEFAULT '00:00:00',
                timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
                report_chat VARCHAR(255) NOT NULL DEFAULT '',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::ensureColumn($pdo, 'reporting_settings', 'scope', "VARCHAR(16) NOT NULL DEFAULT 'all'");
        $pdo->exec("INSERT IGNORE INTO reporting_settings(id) VALUES (1)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reporting_rule_scope (
                rule_id BIGINT UNSIGNED PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reporting_tickets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                source_chat VARCHAR(255) NOT NULL,
                destination_chat VARCHAR(255) NOT NULL,
                source_message_id BIGINT NOT NULL,
                bet_kind VARCHAR(32) NOT NULL DEFAULT 'simple',
                bookmaker VARCHAR(120) NOT NULL DEFAULT '',
                total_odds DECIMAL(10,3) NULL,
                stake_units DECIMAL(10,2) NOT NULL DEFAULT 10.00,
                status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
                profit_units DECIMAL(12,4) NOT NULL DEFAULT 0,
                manual_status_override VARCHAR(32) NULL,
                manual_status_updated_at DATETIME NULL,
                placed_at DATETIME NOT NULL,
                settled_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_reporting_ticket_source (source_chat, destination_chat, source_message_id),
                KEY idx_reporting_ticket_day (placed_at, destination_chat),
                KEY idx_reporting_ticket_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::ensureColumn($pdo, 'reporting_tickets', 'manual_status_override', "VARCHAR(32) NULL");
        self::ensureColumn($pdo, 'reporting_tickets', 'manual_status_updated_at', "DATETIME NULL");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reporting_legs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT UNSIGNED NOT NULL,
                position_no SMALLINT UNSIGNED NOT NULL,
                fixture_id BIGINT NULL,
                sport VARCHAR(64) NOT NULL DEFAULT '',
                match_name VARCHAR(255) NOT NULL,
                league VARCHAR(190) NOT NULL DEFAULT '',
                event_date DATE NULL,
                kickoff_at DATETIME NULL,
                next_check_at DATETIME NULL,
                api_home_team VARCHAR(190) NULL,
                api_away_team VARCHAR(190) NULL,
                market_text VARCHAR(255) NOT NULL,
                selection_text VARCHAR(255) NOT NULL,
                market_key VARCHAR(64) NOT NULL DEFAULT 'unsupported',
                side VARCHAR(16) NOT NULL DEFAULT '',
                line_value DECIMAL(10,3) NULL,
                target_team VARCHAR(190) NULL,
                odds DECIMAL(10,3) NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
                match_confidence DECIMAL(6,4) NULL,
                lookup_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                lookup_last_reason VARCHAR(64) NULL,
                settlement_details JSON NULL,
                settled_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_reporting_leg_position (ticket_id, position_no),
                KEY idx_reporting_leg_fixture (fixture_id, status, next_check_at),
                KEY idx_reporting_leg_match (status, fixture_id, event_date),
                CONSTRAINT fk_reporting_leg_ticket
                    FOREIGN KEY (ticket_id) REFERENCES reporting_tickets(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::ensureColumn($pdo, 'reporting_legs', 'lookup_attempts', "SMALLINT UNSIGNED NOT NULL DEFAULT 0");
        self::ensureColumn($pdo, 'reporting_legs', 'lookup_last_reason', "VARCHAR(64) NULL");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reporting_daily_reports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                report_date DATE NOT NULL,
                destination_chat VARCHAR(255) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'QUEUED',
                payload TEXT NOT NULL,
                generated_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                telegram_message_id BIGINT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_reporting_daily (report_date, destination_chat)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reporting_outbox (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kind VARCHAR(32) NOT NULL,
                dedupe_key VARCHAR(255) NOT NULL,
                destination_chat VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                report_id BIGINT UNSIGNED NULL,
                telegram_random_id BIGINT NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'PENDING',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                available_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                sent_at DATETIME NULL,
                telegram_message_id BIGINT NULL,
                last_error VARCHAR(500) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_reporting_outbox_dedupe (dedupe_key),
                KEY idx_reporting_outbox_pending (status, available_at),
                CONSTRAINT fk_reporting_outbox_report
                    FOREIGN KEY (report_id) REFERENCES reporting_daily_reports(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reporting_team_aliases (
                alias_key VARCHAR(190) PRIMARY KEY,
                alias_text VARCHAR(190) NOT NULL,
                team_id BIGINT UNSIGNED NOT NULL,
                api_name VARCHAR(190) NOT NULL,
                country VARCHAR(120) NOT NULL DEFAULT '',
                confidence DECIMAL(6,4) NOT NULL DEFAULT 1.0000,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_reporting_team_alias_team (team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reporting_runtime_state (
                state_key VARCHAR(64) PRIMARY KEY,
                state_value TEXT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::setState('schema_version', (string)self::VERSION);
        self::$migrated = true;
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    private static function dropLegacy(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'result_tracking_rules',
            'result_tracking_daily_reports',
            'result_tracking_bets',
            'result_tracking_settings',
        ] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public static function setState(string $key, ?string $value): void
    {
        $stmt = Database::pdo()->prepare("
            INSERT INTO reporting_runtime_state(state_key, state_value)
            VALUES(?, ?)
            ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)
        ");
        $stmt->execute([$key, $value]);
    }
}
