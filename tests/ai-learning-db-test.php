<?php declare(strict_types=1);
require __DIR__.'/../app/AiLearningMemory.php';

use App\AiLearningMemory;

$pdo=new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=ai_learning_ci;charset=utf8mb4',
    'root','test-only-not-production',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);
final class AiLearningTestDatabase {
    public static ?PDO $connection=null;
    public static function pdo(): PDO { return self::$connection ?? throw new RuntimeException('Missing test PDO'); }
}
class_alias(AiLearningTestDatabase::class, 'App\\Database');
AiLearningTestDatabase::$connection=$pdo;
$store=new AiLearningMemory($pdo);
$store->migrate();
$store->migrate();
$id=$store->savePending(
    'Liverpool x Aston Villa, Over 10.5 Corners, odds 1.72',
    ['match'=>'Liverpool x Aston Villa','market'=>'Escanteios totais',
        'selection'=>'Mais de 10,5','odd'=>'1.72'],null,25
);
// An encrypted screenshot can be saved first and labeled later.
$imageName=str_repeat('a',32).'.png.enc';
$pendingImageId=$store->savePending('',[],$imageName,null);
$pendingImage=$store->get($pendingImageId);
if(($pendingImage['status']??'')!=='pending' ||
   ($pendingImage['image_name']??'')!==$imageName)
    throw new RuntimeException('Encrypted image-only pending registration failed');
$pendingLabel=json_decode((string)$pendingImage['expected_json'],true);
if(($pendingLabel['match']??'NOT_NULL')!=='')
    throw new RuntimeException('Incomplete pending image invented labels');
try {
    $store->review($pendingImageId,'approved',$pendingLabel);
    throw new RuntimeException('Image-only draft approved without labels');
} catch(RuntimeException $e) {
    if($e->getMessage()!=='Informe confronto, mercado e seleção.')
        throw $e;
}
$pendingLabel['match']='Liverpool x Aston Villa';
$pendingLabel['market']='Escanteios totais';
$pendingLabel['selection']='Mais de 10,5';
$store->review($pendingImageId,'approved',$pendingLabel);
if(($store->get($pendingImageId)['status']??'')!=='approved')
    throw new RuntimeException('Completed screenshot draft could not be approved');
try {
    $store->savePending('',[],'invalid.png',null);
    throw new RuntimeException('Raw/invalid uploaded screenshot filename was accepted');
} catch(RuntimeException $e) {
    if($e->getMessage()!=='Nome de imagem inválido.')throw $e;
}

if($id<=0)throw new RuntimeException('No example created');
$row=$store->get($id);
if(($row['status']??'')!=='pending')throw new RuntimeException('Example auto-approved');
$before=json_decode((string)$row['expected_json'],true);
$before['market']='Total de escanteios';
$store->review($id,'approved',$before);
$after=$store->get($id);
$label=json_decode((string)$after['expected_json'],true);
if(($after['status']??'')!=='approved' || $label['market']!=='Total de escanteios')
    throw new RuntimeException('Correction was not persisted');
$matched=AiLearningMemory::bestExamples(
    'Liverpool Aston Villa Over 10,5 corners',
    [[ 'id'=>$id,'rule_id'=>25,'status'=>'approved',
       'source_text'=>$after['source_text'],'expected_json'=>$after['expected_json'] ]],
    25
);
if(count($matched)!==1)throw new RuntimeException('Approved example not retrieved');
if(AiLearningMemory::bestExamples('Liverpool Aston Villa Over 10.5 corners',$matched,26)!==[])
    throw new RuntimeException('Cross-rule memory contamination');
$store->review($id,'rejected',$before);
if(($store->get($id)['status']??'')!=='rejected')
    throw new RuntimeException('Rejection did not revoke approval');
$audit=(int)$pdo->query('SELECT COUNT(*) FROM tmr_ai_learning_audit WHERE example_id='.$id)->fetchColumn();
if($audit!==2)throw new RuntimeException('Missing review audit history');

// Exercise the actual production retrieval path with a test-only Database stub,
// never connecting to the Railway application database.
putenv('AI_LEARNING_ENABLED=1');
$context=AiLearningMemory::contextFor('Liverpool Aston Villa Over 10,5 corners',25);
if($context!=='')throw new RuntimeException('Rejected example leaked into prompt');
$store->review($id,'approved',$before);
$context=AiLearningMemory::contextFor('Liverpool Aston Villa Over 10,5 corners',25);
if(!str_contains($context,'EXEMPLOS ANTERIORES APROVADOS') ||
   !str_contains($context,'Total de escanteios'))
    throw new RuntimeException('Approved example was not injected into context');
$cross=AiLearningMemory::contextFor('Liverpool Aston Villa Over 10,5 corners',26);
if($cross!=='')throw new RuntimeException('Cross-rule example leaked into context');
putenv('AI_LEARNING_ENABLED=0');
if(AiLearningMemory::contextFor('Liverpool Aston Villa Over 10,5 corners',25)!=='')
    throw new RuntimeException('Feature flag failed to isolate context');
echo "AI_LEARNING_MYSQL_INTEGRATION_PASSED\n";
