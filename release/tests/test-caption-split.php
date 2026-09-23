<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$ref=new ReflectionClass(App\TelegramRouter::class);
$obj=$ref->newInstanceWithoutConstructor();
$m=$ref->getMethod('splitCaption'); $m->setAccessible(true);
$text=str_repeat('a',1018).'negrito'.str_repeat('b',100);
$entities=[['_'=>'messageEntityBold','offset'=>1018,'length'=>7],['_'=>'messageEntityItalic','offset'=>1020,'length'=>10]];
[$first,$rest,$firstEntities,$restEntities]=$m->invoke($obj,$text,$entities,1024);
if ($rest==='') throw new RuntimeException('A continuação não foi criada.');
if (($firstEntities[0]['_']??'')!=='messageEntityBold' || ($firstEntities[1]['_']??'')!=='messageEntityItalic') throw new RuntimeException('Formatação inicial não preservada.');
if (($restEntities[0]['_']??'')!=='messageEntityBold' || ($restEntities[0]['offset']??-1)!==0 || ($restEntities[1]['_']??'')!=='messageEntityItalic' || ($restEntities[1]['offset']??-1)!==0) throw new RuntimeException('Formatação da continuação não foi reajustada.');
echo "Negrito e itálico preservados na divisão\n";
