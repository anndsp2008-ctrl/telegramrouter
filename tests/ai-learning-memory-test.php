<?php declare(strict_types=1);
require __DIR__.'/../app/AiLearningMemory.php';

use App\AiLearningMemory;

function aiAssert(bool $condition,string $reason): void {
    if(!$condition)throw new RuntimeException($reason);
}
$label=['match'=>'Liverpool x Aston Villa','market'=>'Escanteios totais',
    'selection'=>'Mais de 10,5','odd'=>'1.72'];
$normalized=AiLearningMemory::fields($label);
aiAssert($normalized['match']==='Liverpool x Aston Villa','Match changed');
aiAssert($normalized['odd']==='1.72','Odds changed');
aiAssert($normalized['analysis']==='','Absent field was invented');
try { AiLearningMemory::fields(['match'=>'X','market'=>'Y','selection'=>'']); throw new RuntimeException('Required field was accepted'); }
catch(RuntimeException $e){aiAssert($e->getMessage()==='Informe confronto, mercado e seleção.','Unexpected validation failure');}

$items=[
    ['id'=>1,'rule_id'=>null,'status'=>'approved','source_text'=>'Liverpool Aston Villa Over 10.5 corners',
        'expected_json'=>json_encode($normalized)],
    ['id'=>2,'rule_id'=>null,'status'=>'pending','source_text'=>'Liverpool Aston Villa Over 10.5 corners',
        'expected_json'=>json_encode(['market'=>'OVER 10.5 GOALS'])],
    ['id'=>3,'rule_id'=>25,'status'=>'approved','source_text'=>'Liverpool Aston Villa Over 10.5 corners',
        'expected_json'=>json_encode(['market'=>'Wrong-rule example'])],
    ['id'=>4,'rule_id'=>null,'status'=>'approved','source_text'=>'Manchester City Chelsea BTTS yes',
        'expected_json'=>json_encode(['market'=>'Both score'])],
];
$selected=AiLearningMemory::bestExamples('Liverpool Aston Villa Over 10,5 corners',$items,6);
aiAssert(count($selected)===1 && $selected[0]['id']===1,'Approval/scope/decimal filtering failed');
aiAssert(AiLearningMemory::bestExamples('hi',$items,6)===[],'Short input unexpectedly matched');
aiAssert(AiLearningMemory::bestExamples('Real Sociedad Real Madrid under goals',$items,6)===[],
    'Unrelated bet unexpectedly matched');
putenv('AI_LEARNING_ENABLED=0');
aiAssert(AiLearningMemory::contextFor('Liverpool Aston Villa Over 10.5 corners',6)==='',
    'Disabled memory must not access database');
echo "AI_LEARNING_MEMORY_TESTS_PASSED\n";
