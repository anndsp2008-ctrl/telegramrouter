<?php declare(strict_types=1);
/**
 * Production-safe wrapper for the original translation-v2 migration.
 *
 * The original migration downgrades translation_provider ENUM and resets
 * global provider settings. It must NEVER run after Workers AI has been
 * installed because that would destroy a valid provider selection and fail
 * when router_rules contains workers_ai.
 *
 * Fresh installations still execute the original migration unchanged.
 * The main startup command invokes this shim instead of the old script.
 */
require_once dirname(__DIR__).'/bootstrap.php';
$pdo=\App\Database::pdo();
$statement=$pdo->prepare(
    'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
);
$statement->execute(['router_rules','translation_provider']);
$columnType=strtolower((string)($statement->fetchColumn()?:''));
if(str_contains($columnType,"'workers_ai'")){
    // Temporary, strictly allowlisted startup diagnostic for the other legacy
    // installer. Do not log tokens, API payloads, tip text or database rows.
    $googleInstaller='/tmp/router-google-cloud/install.php';
    $code=@file($googleInstaller,FILE_IGNORE_NEW_LINES);
    if(is_array($code)){
        foreach($code as $i=>$line){
            if($i>115)break;
            if(preg_match('/translation_provider|translation_primary_provider|translation_fallback_provider|ENUM\\(/i',$line)){
                error_log('TMR_GOOGLE_INSTALL_DIAG '.($i+1).' '.substr(trim($line),0,320));
            }
        }
    }
    echo "TRANSLATION_V2_MIGRATION_SKIPPED_NEW_PROVIDER_ACTIVE\n";
    exit(0);
}
// A new/fresh database still needs the full original translation-v2 schema
// and its data migration. Run in its own PHP process to avoid double bootstrap.
$code=1;
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/translation-migrate.php'),$code);
exit($code);
