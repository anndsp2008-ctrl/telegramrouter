<?php declare(strict_types=1);
/**
 * Run the existing Google Cloud compatibility installer without narrowing a
 * production enum already containing Workers AI. The original installer is
 * unpacked by the existing startup command into /tmp/router-google-cloud.
 * No database rows or provider selections are changed by this wrapper.
 */
require_once dirname(__DIR__).'/bootstrap.php';
$installer='/tmp/router-google-cloud/install.php';
if(!is_file($installer))throw new RuntimeException('GOOGLE_CLOUD_INSTALLER_MISSING');
$pdo=\App\Database::pdo();
$query=$pdo->prepare(
    'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
);
$query->execute(['router_rules','translation_provider']);
$columnType=strtolower((string)($query->fetchColumn()?:''));
if($columnType==='')throw new RuntimeException('ROUTER_PROVIDER_COLUMN_MISSING');
if(str_contains($columnType,"'workers_ai'")){
    $source=file_get_contents($installer);
    if(!is_string($source))throw new RuntimeException('GOOGLE_CLOUD_INSTALLER_UNREADABLE');
    $patches=[
        '$pdo->exec("ALTER TABLE router_rules MODIFY translation_provider ENUM('
        ."'azure','gemini','google_cloud'".') NOT NULL DEFAULT '
        ."'azure'".'");'
        =>
        '$pdo->exec("ALTER TABLE router_rules MODIFY translation_provider ENUM('
        ."'azure','gemini','google_cloud','workers_ai'".') NOT NULL DEFAULT '
        ."'azure'".'");',
        '$pdo->exec("ALTER TABLE translation_attempts MODIFY provider ENUM('
        ."'azure','gemini','google_cloud'".') NOT NULL");'
        =>
        '$pdo->exec("ALTER TABLE translation_attempts MODIFY provider ENUM('
        ."'azure','gemini','google_cloud','workers_ai'".') NOT NULL");'
    ];
    foreach($patches as $old=>$new){
        if(substr_count($source,$old)!==1){
            throw new RuntimeException('GOOGLE_CLOUD_INSTALLER_SCHEMA_ANCHOR_CHANGED');
        }
        $source=str_replace($old,$new,$source);
    }
    // The installer is a temporary extracted copy, never a persistent file.
    // Keep its location unchanged so relative includes keep working.
    if(file_put_contents($installer,$source,LOCK_EX)===false)
        throw new RuntimeException('GOOGLE_CLOUD_INSTALLER_PATCH_FAILED');
    echo "GOOGLE_CLOUD_WORKERS_ENUM_PRESERVED\n";
}
$exit=1;
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($installer),$exit);
exit($exit);
