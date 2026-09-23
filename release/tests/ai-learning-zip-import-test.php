<?php declare(strict_types=1);
require __DIR__.'/../app/AiLearningMemory.php';
require __DIR__.'/../app/AiLearningZipImporter.php';

use App\AiLearningMemory;
use App\AiLearningZipImporter;

$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=ai_learning_ci;charset=utf8mb4',
    'root','test-only-not-production',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
putenv('APP_KEY=ci-only-image-encryption-secret-at-least-32-characters');
$memory=new AiLearningMemory($pdo);
$memory->migrate();
$before=$memory->statusCounts();
$dir=sys_get_temp_dir().'/tmr-ai-import-test-'.bin2hex(random_bytes(6));
if(!mkdir($dir,0700,true))throw new RuntimeException('Failed to create test directory');
$zipPath=$dir.'/dataset.zip';
$zip=new ZipArchive();
if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)
    throw new RuntimeException('Failed to create fixture ZIP');
$pngs=[];
for($i=1;$i<=9;$i++){
    $id=str_pad((string)$i,3,'0',STR_PAD_LEFT);
    $image=imagecreatetruecolor(8,8);
    $color=imagecolorallocate($image,$i*20,$i*15,$i*10);
    imagefilledrectangle($image,0,0,7,7,$color);
    ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
    if(!is_string($bytes))throw new RuntimeException('No PNG bytes');
    $pngs[$id]=$bytes;
    $leg=['home_team'=>'Liverpool','away_team'=>'Aston Villa',
        'market'=>'total_corners','market_verbatim'=>'Córners - Total',
        'selection'=>'over','selection_verbatim'=>'Más de 10.5',
        'line'=>10.5,'odds'=>1.72,'event_date_verbatim'=>'24/08/2026','event_time'=>'16:00'];
    $legs=$i===5?[$leg,array_merge($leg,['home_team'=>'AC Milan','away_team'=>'US Lecce',
        'market'=>'match_result','market_verbatim'=>'Resultado del partido',
        'selection'=>'AC Milan','selection_verbatim'=>'AC Milan','line'=>null,'odds'=>1.28])]:[$leg];
    $label=['id'=>$id,'source_type'=>'synthetic','bookmaker'=>'Betano',
        'bet_type'=>count($legs)>1?'multiple':'single',
        'status'=>null,'selections'=>$legs,'combined_odds'=>1.72,
        'image'=>'images/'.$id.'_test.png'];
    $zip->addFromString('training/labels/'.$id.'.json',
        json_encode($label,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $zip->addFromString('training/images/'.$id.'_test.png',$bytes);
}
$zip->close();

// Previously registered image must not be imported a second time.
$manualName=bin2hex(random_bytes(16)).'.png.enc';
file_put_contents($dir.'/'.$manualName,AiLearningMemory::encryptImage($pngs['001']));
$manualId=$memory->savePending('Existing manual screenshot',[],$manualName);
$importer=new AiLearningZipImporter($pdo,$memory);
$first=$importer->import($zipPath,$dir,filesize($zipPath));
if($first!==['created'=>8,'skipped'=>1])
    throw new RuntimeException('First import did not skip existing image: '.json_encode($first));
$second=$importer->import($zipPath,$dir,filesize($zipPath));
if($second!==['created'=>0,'skipped'=>9])
    throw new RuntimeException('Repeated ZIP created duplicates: '.json_encode($second));
$after=$memory->statusCounts();
if($after['pending']-$before['pending']!==9||$after['approved']!==$before['approved'])
    throw new RuntimeException('Importer unexpectedly approved or omitted examples');
$rows=$pdo->query('SELECT id,source_text,image_name,expected_json,status FROM tmr_ai_learning_examples
    WHERE source_text LIKE "[EXEMPLO SINTÉTICO%" ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if(count($rows)!==8)throw new RuntimeException('Incorrect synthetic record count');
$multiple=0;
foreach($rows as $row){
    $labels=json_decode((string)$row['expected_json'],true,32,JSON_THROW_ON_ERROR);
    if($row['status']!=='pending')throw new RuntimeException('Imported example auto-approved');
    if(str_contains((string)$row['source_text'],'Tipo: Múltipla')){
        ++$multiple;
        if(($labels['match']??'not blank')!==''||($labels['selection']??'not blank')!=='')
            throw new RuntimeException('Multiple legs were flattened into an approved-looking selection');
    }
    $path=$dir.'/'.$row['image_name'];
    $encrypted=file_get_contents($path);
    if(!str_starts_with((string)$encrypted,'TMRIMG1'))
        throw new RuntimeException('Imported image was not encrypted at rest');
    if(!str_starts_with(AiLearningMemory::decryptImage((string)$encrypted),"\x89PNG"))
        throw new RuntimeException('Encrypted image could not be opened');
}
if($multiple!==1)throw new RuntimeException('Multiple bet was not preserved for review');
$unsafe=$dir.'/unsafe.zip';$bad=new ZipArchive();
$bad->open($unsafe,ZipArchive::CREATE|ZipArchive::OVERWRITE);
$bad->addFromString('../labels/001.json','{}');$bad->close();
try{
    $importer->import($unsafe,$dir,filesize($unsafe));
    throw new RuntimeException('Unsafe ZIP was accepted');
}catch(RuntimeException $e){
    if($e->getMessage()!=='O pacote deve conter os nove rótulos 001–009.')
        throw $e;
}
foreach(glob($dir.'/*') as $path)@unlink($path);
@rmdir($dir);
echo "AI_LEARNING_ZIP_IMPORT_TESTS_PASSED\n";
