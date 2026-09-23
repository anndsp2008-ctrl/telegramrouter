<?php declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Database;
use App\Repository;

$pdo=Database::pdo();
$pdo->exec("CREATE TABLE IF NOT EXISTS translation_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_chat VARCHAR(255) NOT NULL DEFAULT '',
  message_id BIGINT NOT NULL DEFAULT 0,
  rule_id INT UNSIGNED NULL,
  provider ENUM('azure','gemini','google_cloud') NOT NULL,
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
  KEY provider_context_created (provider,context,created_at),
  KEY event_lookup (source_chat,message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS translation_provider_status (
  provider VARCHAR(16) PRIMARY KEY,
  last_test_ok TINYINT(1) NULL,
  last_test_at DATETIME NULL,
  last_test_latency_ms INT UNSIGNED NULL,
  last_test_http_code SMALLINT UNSIGNED NULL,
  last_test_error VARCHAR(1000) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS translation_migrations (
  version VARCHAR(64) PRIMARY KEY,
  details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Migra a credencial Gemini já existente no ambiente para o cofre cifrado do banco,
// somente se o administrador ainda não tiver salvo uma chave no módulo Integrações.
if(Repository::integration('gemini_api_key')==='') {
    $envKey=trim((string)getenv('GEMINI_API_KEY'));
    if($envKey!=='') Repository::saveIntegration('gemini_api_key',$envKey);
}
if(Repository::integration('gemini_model')==='') {
    $envModel=trim((string)getenv('GEMINI_MODEL'));
    Repository::saveIntegration('gemini_model',$envModel!==''?$envModel:'gemini-3.6-flash');
}

$azureConfigured=Repository::integration('azure_translator_api_key')!=='';
$geminiConfigured=Repository::integration('gemini_api_key')!=='';

$primary=Repository::integration('translation_primary_provider');
$fallback=Repository::integration('translation_fallback_provider');
if(!in_array($primary,['azure','gemini','google_cloud'],true)) {
    $primary=$azureConfigured?'azure':($geminiConfigured?'gemini':'azure');
}
if(!in_array($fallback,['azure','gemini','google_cloud','none'],true) || $fallback===$primary) {
    $fallback=($azureConfigured&&$geminiConfigured)?($primary==='azure'?'gemini':'azure'):'none';
}
Repository::saveIntegration("translation_primary_provider",$primary); Repository::saveIntegration("translation_fallback_provider",$fallback);

// Primeiro amplia o ENUM, migra os registros legados e só então elimina os valores antigos.
try {
    $pdo->exec("ALTER TABLE router_rules MODIFY translation_provider ENUM('none','libretranslate','gemini','azure','google_cloud') NOT NULL DEFAULT 'none'");
} catch(Throwable $e) {
    if(!str_contains(strtolower($e->getMessage()),'duplicate')) throw $e;
}

$legacy=$pdo->query("SELECT id,translation_enabled,translation_provider FROM router_rules WHERE translation_provider NOT IN ('azure','gemini','google_cloud')")->fetchAll();
$migrated=[];$disabled=[];
foreach($legacy as $row) {
    $id=(int)$row['id'];
    $enabled=(int)$row['translation_enabled']===1;
    $replacement=$azureConfigured?'azure':($geminiConfigured?'gemini':null);
    if($replacement===null) {
        // Sem credencial válida não inventamos um tradutor operacional: desativamos a tradução da regra.
        $replacement='gemini';
        if($enabled) $disabled[]=$id;
        $pdo->prepare('UPDATE router_rules SET translation_enabled=0,translation_provider=? WHERE id=?')->execute([$replacement,$id]);
    } else {
        $pdo->prepare('UPDATE router_rules SET translation_provider=? WHERE id=?')->execute([$replacement,$id]);
    }
    $migrated[]=$id;
}

$pdo->exec("ALTER TABLE router_rules MODIFY translation_provider ENUM('azure','gemini','google_cloud') NOT NULL DEFAULT 'azure'");

// Remove configurações persistidas de provedores antigos. Não toca em integrações não relacionadas à tradução.
$pdo->exec("DELETE FROM app_settings WHERE setting_key='libretranslate_url' OR setting_key='translate_provider' OR setting_key LIKE 'libretranslate_%'");

$details=json_encode([
    'providers'=>['azure','gemini','google_cloud'],
    'azure_configured'=>$azureConfigured,
    'gemini_configured'=>$geminiConfigured,
    'primary'=>$primary,
    'fallback'=>$fallback,
    'migrated_rule_ids'=>$migrated,
    'translation_disabled_rule_ids'=>$disabled,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$st=$pdo->prepare("INSERT INTO translation_migrations(version,details) VALUES('translation-v2-azure-gemini',?) ON DUPLICATE KEY UPDATE details=VALUES(details)");
$st->execute([$details]);

echo 'TRANSLATION_V2_MIGRATION '.($details?:'{}').PHP_EOL;
