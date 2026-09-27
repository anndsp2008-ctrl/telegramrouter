-- Result tracking is isolated from the existing router tables.
-- Apply only when RESULT_TRACKING_ENABLED=1 is intentionally enabled.
CREATE TABLE IF NOT EXISTS result_tracking_bets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_chat VARCHAR(255) NOT NULL,
  destination_chat VARCHAR(255) NOT NULL,
  source_message_id BIGINT NOT NULL,
  fixture_id BIGINT NULL,
  home_team VARCHAR(190) NULL,
  away_team VARCHAR(190) NULL,
  market VARCHAR(64) NOT NULL,
  side VARCHAR(16) NOT NULL,
  line DECIMAL(8,2) NULL,
  odds DECIMAL(8,3) NOT NULL,
  stake_units DECIMAL(8,2) NOT NULL DEFAULT 10.00,
  status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  profit_units DECIMAL(10,4) NOT NULL DEFAULT 0,
  placed_at DATETIME NOT NULL,
  settled_at DATETIME NULL,
  raw_tip TEXT NULL,
  settlement_details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tracking_source_message (source_chat, source_message_id, market, side, line),
  KEY idx_tracking_status_fixture (status, fixture_id),
  KEY idx_tracking_placed_at (placed_at)
);

CREATE TABLE IF NOT EXISTS result_tracking_daily_reports (
  report_date DATE PRIMARY KEY,
  bets_total INT UNSIGNED NOT NULL DEFAULT 0,
  settled_total INT UNSIGNED NOT NULL DEFAULT 0,
  pending_total INT UNSIGNED NOT NULL DEFAULT 0,
  greens INT UNSIGNED NOT NULL DEFAULT 0,
  reds INT UNSIGNED NOT NULL DEFAULT 0,
  voids INT UNSIGNED NOT NULL DEFAULT 0,
  half_greens INT UNSIGNED NOT NULL DEFAULT 0,
  half_reds INT UNSIGNED NOT NULL DEFAULT 0,
  profit_units DECIMAL(12,4) NOT NULL DEFAULT 0,
  sent_at DATETIME NULL,
  telegram_message_id BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
