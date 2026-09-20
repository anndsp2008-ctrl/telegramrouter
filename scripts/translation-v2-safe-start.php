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
    echo "TRANSLATION_V2_MIGRATION_SKIPPED_NEW_PROVIDER_ACTIVE\n";
    exit(0);
}
// A new/fresh database still needs the full original translation-v2 schema
// and its data migration. Run in its own PHP process to avoid double bootstrap.
$code=1;
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/translation-migrate.php'),$code);
exit($code);
