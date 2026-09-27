<?php declare(strict_types=1);

require_once __DIR__.'/../app/AdaptiveVipCardRenderer.php';

use App\AdaptiveVipCardRenderer;

$fixtures=[
    [
        'expected'=>'simple',
        'data'=>[
            'bookmaker'=>'Betano','bet_kind'=>'single','selections_count'=>'1',
            'sport'=>'Futebol','status'=>'','odd'=>'1,82','day'=>'26/09/2026',
            'match'=>'Inglaterra x Espanha','league'=>'Amistoso Internacional',
            'market'=>'Ambas as equipes marcam','selection'=>'Sim',
            'analysis'=>'Confronto equilibrado, com uma seleção objetiva e coerente com a proposta da entrada.',
            'card_legs'=>json_encode([[
                'match'=>'Inglaterra x Espanha','league'=>'Amistoso Internacional','date'=>'26/09/2026',
                'market'=>'Ambas as equipes marcam','selection'=>'Sim','odd'=>'1,82'
            ]],JSON_UNESCAPED_UNICODE)
        ]
    ],
    [
        'expected'=>'double',
        'data'=>[
            'bookmaker'=>'bet365','bet_kind'=>'multiple','selections_count'=>'2',
            'sport'=>'Futebol','odd'=>'2.18',
            'analysis'=>'Duas seleções em jogos distintos formam uma dupla, mantendo leitura separada de cada confronto.',
            'card_legs'=>json_encode([
                ['match'=>'Inglaterra x Espanha','league'=>'Amistoso Internacional','date'=>'26/09/2026','market'=>'Ambas as equipes marcam','selection'=>'Sim','odd'=>'1.40'],
                ['match'=>'Alemanha x França','league'=>'Amistoso Internacional','date'=>'26/09/2026','market'=>'Total de gols','selection'=>'Mais de 1,5 gols','odd'=>'1.56']
            ],JSON_UNESCAPED_UNICODE)
        ]
    ],
    [
        'expected'=>'multiple',
        'data'=>[
            'bookmaker'=>'Casa X','bet_kind'=>'multiple','selections_count'=>'3',
            'sport'=>'Futebol','odd'=>'4.86',
            'analysis'=>'A múltipla combina três mercados independentes, exigindo acerto simultâneo em todos os jogos.',
            'card_legs'=>json_encode([
                ['match'=>'Inglaterra x Espanha','league'=>'Amistoso Internacional','date'=>'26/09/2026','market'=>'Ambas as equipes marcam','selection'=>'Sim','odd'=>'1.40'],
                ['match'=>'Alemanha x França','league'=>'Amistoso Internacional','date'=>'26/09/2026','market'=>'Total de gols','selection'=>'Mais de 1,5 gols','odd'=>'1.50'],
                ['match'=>'Brasil x Argentina','league'=>'Amistoso Internacional','date'=>'26/09/2026','market'=>'Dupla chance','selection'=>'Brasil ou empate','odd'=>'1.32']
            ],JSON_UNESCAPED_UNICODE)
        ]
    ],
    [
        'expected'=>'bet_builder',
        'data'=>[
            'bookmaker'=>'Betano','bet_kind'=>'bet_builder','selections_count'=>'2',
            'sport'=>'Futebol','odd'=>'1.82','visual_multiple_evidence'=>'Bet Builder',
            'analysis'=>'🔥 As duas condições pertencem ao mesmo confronto e precisam ocorrer em conjunto para a entrada ser vencedora.',
            'card_legs'=>json_encode([
                ['match'=>'Inglaterra x Espanha','league'=>'Amistoso Internacional','date'=>'26/09/2026','market'=>'Ambas as equipes marcam','selection'=>'Sim','odd'=>'1.82'],
                ['match'=>'Inglaterra x Espanha','league'=>'Amistoso Internacional','date'=>'26/09/2026','market'=>'Total de gols','selection'=>'Menos de 5,5 gols','odd'=>'1.82']
            ],JSON_UNESCAPED_UNICODE)
        ]
    ]
];

foreach($fixtures as $fixture){
    $bet=AdaptiveVipCardRenderer::extract($fixture['data'],'',false);
    if($bet===null)throw new RuntimeException('Fixture extraction failed: '.$fixture['expected']);
    if(($bet['kind']??'')!==$fixture['expected'])throw new RuntimeException('Wrong type: '.$fixture['expected']);
    if(($bet['stake']??'')!=='10')throw new RuntimeException('Stake normalization failed');
    if($fixture['expected']==='simple' && ($bet['odd']??'')!=='1.82'){
        throw new RuntimeException('Odd decimal point normalization failed');
    }

    $caption=AdaptiveVipCardRenderer::caption($bet);
    foreach(['13:45','16:00','18:30'] as $forbidden){
        if(str_contains($caption,$forbidden))throw new RuntimeException('Game time leaked into caption');
    }
    foreach(['🎟️','⚽','🏆','📅','🎯','✅','📊','📍','📝'] as $layoutEmoji){
        if(!str_contains($caption,$layoutEmoji))throw new RuntimeException('Structural emoji missing: '.$layoutEmoji);
    }
    if(str_contains($caption,'🔥'))throw new RuntimeException('Analysis emoji leaked into Telegram caption');
    if($fixture['expected']==='simple'){
        if(!str_contains($caption,'📊 Odd total: 1.82'))throw new RuntimeException('Dot odd missing in caption');
        if(str_contains($caption,'Odd total: 1,82'))throw new RuntimeException('Comma odd leaked into caption');
    }
    if(!str_contains($caption,'📝 Análise:'))throw new RuntimeException('Analysis missing from caption');
    if($fixture['expected']==='bet_builder'){
        foreach($bet['legs'] as $leg){
            if(($leg['odd']??'')!=='')throw new RuntimeException('Bet Builder kept per-selection odd');
        }
        if(str_contains($caption,'📈 Odd:'))throw new RuntimeException('Bet Builder caption exposed per-selection odd');
        if(substr_count($caption,'Odd total:')!==1)throw new RuntimeException('Bet Builder must expose one total odd only');
        if(substr_count($caption,'⚽ Inglaterra x Espanha')!==1)throw new RuntimeException('Bet Builder event must be shown once');
        if(str_contains($caption,'⚽ 1.')||str_contains($caption,'⚽ 2.'))throw new RuntimeException('Bet Builder caption looks like separate bets');
        if(!str_contains($caption,'🧩 Seleções (2) da mesma aposta:'))throw new RuntimeException('Bet Builder single-wager wording missing');
    }

    $image=AdaptiveVipCardRenderer::render($bet);
    if($image===null)throw new RuntimeException('Render failed: '.$fixture['expected']);
    $size=getimagesize($image);
    if(!is_array($size)||($size['mime']??'')!=='image/png'||($size[0]??0)!==1199){
        @unlink($image);
        throw new RuntimeException('Invalid PNG: '.$fixture['expected']);
    }
    @unlink($image);
}

$sportCases=[
    ['Futebol','⚽','Brasil x Argentina'],
    ['Basquete','🏀','Boston x Miami'],
    ['Tênis','🎾','Jogador A x Jogador B'],
    ['Vôlei','🏐','Brasil x Itália'],
    ['Tênis de mesa','🏓','Atleta A x Atleta B'],
    ['Baseball','⚾','Yankees x Red Sox'],
    ['Futebol americano','🏈','Chiefs x Bills'],
    ['Hóquei','🏒','Rangers x Bruins'],
    ['eSports','🎮','Team Alpha x Team Beta'],
    ['MMA','🥊','Lutador A x Lutador B'],
    ['Fórmula 1','🏁','GP do Brasil'],
    ['Snooker','🎱','Player A x Player B'],
    ['Dardos','🎯','Player C x Player D'],
    ['Handebol','🤾','Equipe A x Equipe B'],
];
foreach($sportCases as [$sport,$emoji,$match]){
    $data=[
        'bookmaker'=>'Betano','bet_kind'=>'single','selections_count'=>'1',
        'sport'=>$sport,'odd'=>'1.75','match'=>$match,'league'=>'Competição teste',
        'market'=>'Mercado teste','selection'=>'Seleção teste',
        'analysis'=>'Análise esportiva objetiva para validar o ícone do esporte.',
        'card_legs'=>json_encode([[
            'sport'=>$sport,'match'=>$match,'league'=>'Competição teste','date'=>'27/09/2026',
            'market'=>'Mercado teste','selection'=>'Seleção teste','odd'=>'1.75'
        ]],JSON_UNESCAPED_UNICODE)
    ];
    $bet=AdaptiveVipCardRenderer::extract($data,'',false);
    if($bet===null)throw new RuntimeException('Sport fixture extraction failed: '.$sport);
    $caption=AdaptiveVipCardRenderer::caption($bet);
    if(!str_contains($caption,$emoji))throw new RuntimeException('Wrong sport emoji: '.$sport);
    $image=AdaptiveVipCardRenderer::render($bet);
    if($image===null)throw new RuntimeException('Sport icon render failed: '.$sport);
    $size=getimagesize($image);
    @unlink($image);
    if(!is_array($size)||($size['mime']??'')!=='image/png'||($size[0]??0)!==1199){
        throw new RuntimeException('Invalid sport PNG: '.$sport);
    }
}

$identityCases=[
    [
        'sport'=>'Futebol',
        'match'=>'Brasil x Argentina',
        'league'=>'Amistoso Internacional',
        'market'=>'Resultado da partida',
        'selection'=>'Brasil',
        'odd'=>'1,65'
    ],
    [
        'sport'=>'Futebol',
        'match'=>'Club León x América',
        'league'=>'Liga MX',
        'market'=>'Resultado da partida',
        'selection'=>'Club León',
        'odd'=>'1,50'
    ]
];
foreach($identityCases as $case){
    $data=[
        'bookmaker'=>'Betano','bet_kind'=>'single','selections_count'=>'1',
        'sport'=>$case['sport'],'odd'=>$case['odd'],
        'match'=>$case['match'],'league'=>$case['league'],
        'market'=>$case['market'],'selection'=>$case['selection'],
        'analysis'=>'Análise objetiva para validar identidade visual dos participantes.',
        'card_legs'=>json_encode([[
            'sport'=>$case['sport'],'match'=>$case['match'],'league'=>$case['league'],
            'date'=>'27/09/2026','market'=>$case['market'],'selection'=>$case['selection'],'odd'=>$case['odd']
        ]],JSON_UNESCAPED_UNICODE)
    ];
    $bet=AdaptiveVipCardRenderer::extract($data,'',false);
    if($bet===null)throw new RuntimeException('Identity fixture extraction failed: '.$case['match']);
    if(($bet['odd']??'')!==str_replace(',','.',$case['odd'])){
        throw new RuntimeException('Identity fixture odd format failed: '.$case['match']);
    }
    $image=AdaptiveVipCardRenderer::render($bet);
    if($image===null)throw new RuntimeException('Identity render failed: '.$case['match']);
    $size=getimagesize($image);
    @unlink($image);
    if(!is_array($size)||($size['mime']??'')!=='image/png'||($size[0]??0)!==1199){
        throw new RuntimeException('Identity PNG invalid: '.$case['match']);
    }
}

$betano=AdaptiveVipCardRenderer::extract($fixtures[0]['data'],'',false);
$bet365=AdaptiveVipCardRenderer::extract($fixtures[1]['data'],'',false);
if(($betano['bookmaker_key']??'')!=='betano')throw new RuntimeException('Betano detection failed');
if(($bet365['bookmaker_key']??'')!=='bet365')throw new RuntimeException('bet365 detection failed');

$unknown=$fixtures[0]['data'];
$unknown['bookmaker']='';
$unknownBet=AdaptiveVipCardRenderer::extract($unknown,'',false);
if(($unknownBet['bookmaker']??'')!=='Casa desconhecida')throw new RuntimeException('Unknown bookmaker fallback failed');

$rendererSource=file_get_contents(__DIR__.'/../app/AdaptiveVipCardRenderer.php');
if(!is_string($rendererSource)
   ||!str_contains($rendererSource,'drawPremiumCard')
   ||!str_contains($rendererSource,'private static function drawSportIcon')
   ||!str_contains($rendererSource,'private static function resolvePairIdentity')
   ||!str_contains($rendererSource,'private static function resolveParticipantIdentity')
   ||!str_contains($rendererSource,'private static function officialTeamBadgePath')
   ||!str_contains($rendererSource,'private static function drawResolvedIdentity')
   ||!str_contains($rendererSource,'private static function drawFlagSized')
   ||!str_contains($rendererSource,'private static function selectionParticipant')
   ||!str_contains($rendererSource,'All-or-none rule')
   ||!str_contains($rendererSource,'show names only for BOTH sides')
   ||!str_contains($rendererSource,'$marketSelectionGap=15')
   ||!str_contains($rendererSource,"return str_replace(',','.',\$odd)")){
    throw new RuntimeException('Global premium renderer hooks missing');
}
if(str_contains($rendererSource,'private static function drawClubCrest')
   ||str_contains($rendererSource,'Premium shield fallback')){
    throw new RuntimeException('Synthetic crest fallback must stay disabled');
}

// Global footer rule: every bookmaker and every card type must publish the
// same Telegram Router identity, with no bookmaker-specific "APOSTAR NA" CTA.
if(!str_contains($rendererSource,"\$label='Telegram Router - Apostas VIP'")
   ||!str_contains($rendererSource,"self::text(\$im,158,\$footerY+104,self::FIXED_STAKE")
   ||!str_contains($rendererSource,"self::text(\$im,158,\$footerY+142,'unidades'")
   ||!str_contains($rendererSource,'Global footer is text-only. No Telegram icon.')
   ||!str_contains($rendererSource,'Deliberately no glow/')
   ||str_contains($rendererSource,"'APOSTAR NA '")
   ||str_contains($rendererSource,"'APOSTA ENCAMINHADA'")
   ||str_contains($rendererSource,'self::telegramIcon($im,398,$ctaY+50,$greenSoft)')){
    throw new RuntimeException('Global premium footer/clean-background rule missing');
}

$smartRuntime=file_get_contents(__DIR__.'/../runtime-smart-format.php');
if(!is_string($smartRuntime)
   ||!str_contains($smartRuntime,'Generated smart-card captions keep their structural emojis globally')
   ||!str_contains($smartRuntime,":(string)\$formatted['caption'];")){
    throw new RuntimeException('Structural emoji delivery bypass missing');
}

echo "CARD_MODELS_SMOKE_TESTS_PASSED\n";
