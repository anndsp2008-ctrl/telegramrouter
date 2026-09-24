<?php declare(strict_types=1);

require_once __DIR__.'/../app/SmartFormatting.php';

use App\SmartFormatting;

$sample="Simple\nBet Builder\nFC Bayern Munchen (F) x Manchester City (F)\n1. Más de 1.5 - FC Bayern Munchen (F) - Goles totales\n2. Más de 2.5 - Goles totales Más/Menos\nOdd 1.70";

$cases=[
    ['Dupla\nChelsea x Arsenal\nTotal de escanteios: Mais de 8,5\nBarcelona x Girona\nVencedor: Barcelona',true],
    ['Múltipla\nPartida A - Mais de 1,5 gols\nPartida B - Vitória da equipe B\nPartida C - Ambas marcam',true],
    ['Parlay\nTeam A vs Team B\nTeam C vs Team D',true],
    ['2 seleções\nEvento A: Total de gols\nEvento B: Resultado',true],
    ["Seleção: Over 1,5\nSeleção: Over 2,5",true],
    [$sample,true],
    ['Dupla chance\nChelsea ou empate',false],
    ['Double chance\nTeam A or draw',false],
    ['Simple\nBet Builder\n1. Mais de 1,5 gols',false],
    ["Simple\nCriar Aposta\n1. Mais de 1,5 gols\n2. Ambas equipes marcam",true],
    ["Simple\nCrear Apuesta\n1. Más de 1.5 goles\n2. Sí - Ambos marcan",true],
    ['Dupla R$5,00\nSantos Laguna x Cruz Azul\nMais de 1,5\nCF Reboceros x CD Irapuato\nMais de 2,5',true],
    ['Múltipla de 3-seleções\nEscolha A\nEscolha B\nEscolha C',true],
    ['Múltipla de 4-seleções\nEscolha A\nEscolha B\nEscolha C\nEscolha D',true],
    ['Aposta simples\nVencedor: Bayern',false]
];
foreach($cases as [$source,$expected]){
    if(SmartFormatting::sourceIndicatesMultiple($source)!==$expected)
        throw new RuntimeException('Wrong text-only multiple classification: '.bin2hex($source));
}

$imageCases=[
    [[
      'visual_bet_kind'=>'bet_builder','visual_selections_count'=>'2',
      'visual_multiple_evidence'=>'Bet Builder',
      'visual_multiple_details'=>"1. Bayern: mais de 1,5 gols\n2. Partida: mais de 2,5 gols"
    ],true],
    [[
      'visual_bet_kind'=>'multiple','visual_selections_count'=>'3',
      'visual_multiple_evidence'=>'Múltipla de 3-seleções',
      'visual_multiple_details'=>"1. Atlético vence\n2. Escanteios > 8,5\n3. Ambas marcam"
    ],true],
    [[
      'visual_bet_kind'=>'multiple','visual_selections_count'=>'2',
      'visual_multiple_evidence'=>'Dupla',
      'visual_multiple_details'=>"1. Santos Laguna: Mais de 1,5 gols\n2. CF Reboceros: Mais de 2,5 gols"
    ],true],
    [[
      'visual_bet_kind'=>'bet_builder','visual_selections_count'=>'1',
      'visual_multiple_evidence'=>'Bet Builder',
      'visual_multiple_details'=>"1. Mais de 1,5 gols"
    ],false],
    [[
      'visual_bet_kind'=>'single','visual_selections_count'=>'1',
      'visual_multiple_evidence'=>'','visual_multiple_details'=>'',
      'market'=>'Dupla chance','selection'=>'Bayern ou empate'
    ],false],
    [[
      // Regression for event #53266: caption has four numbered alternatives,
      // but the attached receipt contains one actual selection.
      'match'=>'OL Lyonnes (F) x Servette FC Chenois (F)',
      'market'=>'Ganhar ambas as metades','selection'=>'OL Lyonnes (F)',
      'bet_kind'=>'multiple','selections_count'=>'4',
      'multiple_details'=>"1. 1X2 - Vitória do Lyon @1.85\n2. Mais de 1.5 gols @1.90\n3. OL Feminino ganha ambas partes @1.50\n4. OL Feminino ganha ou empata @1.40",
      'visual_bet_kind'=>'single','visual_selections_count'=>'1',
      'visual_multiple_evidence'=>'','visual_multiple_details'=>''
    ],false]
];
foreach($imageCases as [$data,$expected]){
    if(SmartFormatting::isMultipleTicket($data,'',true)!==$expected)
        throw new RuntimeException('Wrong image-extracted multiple classification: '.json_encode($data));
}
if(!SmartFormatting::isMultipleTicket(['bet_kind'=>'single','selections_count'=>'2'],$sample,false))
    throw new RuntimeException('Two-leg Bet Builder was misclassified as single');
$runtime=file_get_contents(__DIR__.'/../runtime-smart-format.php');
foreach([
    'SmartFormatting::multipleDetected()',
    'SmartFormatting::multipleDetails()',
    'SmartFormatting::multipleDetailsTranslated()',
    'ContingencyCardRenderer::render(',
    "'multiple_bet'=>\$multipleDetected",
    "tipo=múltipla"
] as $anchor){
    if(!str_contains((string)$runtime,$anchor))
        throw new RuntimeException('Missing mandatory multi-bet contingency integration: '.$anchor);
}
$source=file_get_contents(__DIR__.'/../app/SmartFormatting.php');
foreach([
    'self::isMultipleTicket($json,$sourceText,$hasImage)',
    "'visual_bet_kind','visual_selections_count','visual_multiple_evidence','visual_multiple_details'",
    'MULTIPLE_DETAILS_MISSING_',
    'MULTIPLE_CONTINGENCY_REQUIRED',
    'self::$multipleDetected=true',
    "visual_selections_count",
    'self::normalizePublishedStakeText($details)'
] as $anchor){
    if(!str_contains((string)$source,$anchor))
        throw new RuntimeException('Missing multi-bet card extraction guard: '.$anchor);
}
echo "MULTIPLE_BET_CONTINGENCY_TESTS_PASSED\n";
