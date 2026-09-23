<?php declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Database;
use App\Repository;
use App\TranslationService;

$pdo=Database::pdo();
$version='translation-v2-smoke-1';
$already=$pdo->prepare('SELECT COUNT(*) FROM translation_migrations WHERE version=?');
$already->execute([$version]);
if((int)$already->fetchColumn()>0){echo "TRANSLATION_SMOKE already_done\n";exit(0);}

$results=[];
foreach(['azure','gemini'] as $provider){
    $configured=$provider==='azure' ? Repository::integration('azure_translator_api_key')!=='' : Repository::integration('gemini_api_key')!=='';
    if(!$configured){$results[$provider]=['configured'=>false,'tested'=>false];continue;}
    $test=TranslationService::testProvider($provider);
    $results[$provider]=['configured'=>true,'tested'=>true,'ok'=>$test['ok'],'latency_ms'=>$test['latency_ms'],'http_code'=>$test['http_code']];
}
$details=json_encode($results,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$pdo->prepare('INSERT INTO translation_migrations(version,details) VALUES(?,?)')->execute([$version,$details]);
echo 'TRANSLATION_SMOKE '.($details?:'{}').PHP_EOL;
