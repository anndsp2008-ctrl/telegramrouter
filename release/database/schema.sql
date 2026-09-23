CREATE TABLE IF NOT EXISTS router_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_chat VARCHAR(255) NOT NULL,
  trigger_text VARCHAR(500) NOT NULL,
  destination_chat VARCHAR(255) NOT NULL,
  media_mode ENUM('text_only','current_media','previous_photo') NOT NULL DEFAULT 'previous_photo',
  remove_links TINYINT(1) NOT NULL DEFAULT 1,
  remove_emojis TINYINT(1) NOT NULL DEFAULT 1,
  custom_removals TEXT NULL,
  translation_enabled TINYINT(1) NOT NULL DEFAULT 0,
  translation_provider ENUM('azure','gemini','google_cloud','workers_ai') NOT NULL DEFAULT 'azure',
  translation_source_language VARCHAR(16) NOT NULL DEFAULT 'auto',
  translation_target_language VARCHAR(16) NOT NULL DEFAULT 'pt-BR',
  translation_fallback_original TINYINT(1) NOT NULL DEFAULT 1,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY owner_source_trigger (source_chat, trigger_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS router_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_chat VARCHAR(255) NOT NULL,
  message_id BIGINT NOT NULL,
  media_message_id BIGINT NULL,
  destination_chat VARCHAR(255) NULL,
  trigger_text VARCHAR(500) NULL,
  status ENUM('processing','forwarded','skipped','failed') NOT NULL DEFAULT 'processing',
  details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY source_message (source_chat, message_id),
  KEY status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS router_media_claims (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_chat VARCHAR(255) NOT NULL,
  media_message_id BIGINT NOT NULL,
  event_message_id BIGINT NOT NULL,
  media_date DATETIME NOT NULL,
  event_date DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY source_media (source_chat, media_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_status (
  worker_key VARCHAR(64) PRIMARY KEY,
  status ENUM('conectado','processando','reconectando','erro','desconectado') NOT NULL,
  last_activity_at DATETIME NOT NULL,
  last_error TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS translation_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_chat VARCHAR(255) NOT NULL DEFAULT '',
  message_id BIGINT NOT NULL DEFAULT 0,
  rule_id INT UNSIGNED NULL,
  provider ENUM('azure','gemini','google_cloud','workers_ai') NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  fallback_used TINYINT(1) NOT NULL DEFAULT 0,
  http_code SMALLINT UNSIGNED NULL,
  source_language VARCHAR(24) NULL,
  target_language VARCHAR(24) NOT NULL DEFAULT 'pt-BR',
  latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
  text_chars INT UNSIGNED NOT NULL DEFAULT 0,
  text_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  error_text VARCHAR(1000) NULL,
  context ENUM('message','test') NOT NULL DEFAULT 'message',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY provider_context_created (provider, context, created_at),
  KEY event_lookup (source_chat, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translation_provider_status (
  provider VARCHAR(16) PRIMARY KEY,
  last_test_ok TINYINT(1) NULL,
  last_test_at DATETIME NULL,
  last_test_latency_ms INT UNSIGNED NULL,
  last_test_http_code SMALLINT UNSIGNED NULL,
  last_test_error VARCHAR(1000) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translation_migrations (
  version VARCHAR(64) PRIMARY KEY,
  details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
