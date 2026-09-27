<?php declare(strict_types=1);

require_once __DIR__.'/../app/AdaptiveVipCardRenderer.php';

use App\AdaptiveVipCardRenderer;

$fixtures=[
    [
        'expected'=>'simple',
        'data'=>[
            'bookmaker'=>'Betano','bet_kind'=>'single','selections_count'=>'1',
            'sport'=>'Futebol','status'=>'','odd'=>'1.82','day'=>'26/09/2026',
            'match'=>'Inglaterra x Espanha','league'=>'Amistoso Internacional',
            'market'=>'Ambas as equipes marcam','selection'=>'Sim',
            'analysis'=>'Confronto equilibrado, com uma seleção objetiva e coerente com a proposta da entrada.',
            'card_legs'=>json_encode([[
                'match'=>'Inglaterra x Espanha','league'=>'Amistoso Internacional','date'=>'26/09/2026',
                'market'=>'Ambas as equipes marcam','selection'=>'Sim','odd'=>'1.82'
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

    $caption=AdaptiveVipCardRenderer::caption($bet);
    foreach(['13:45','16:00','18:30'] as $forbidden){
        if(str_contains($caption,$forbidden))throw new RuntimeException('Game time leaked into caption');
    }
    foreach(['🎟️','⚽','🏆','📅','🎯','✅','📊','📍','📝'] as $layoutEmoji){
        if(!str_contains($caption,$layoutEmoji))throw new RuntimeException('Structural emoji missing: '.$layoutEmoji);
    }
    if(str_contains($caption,'🔥'))throw new RuntimeException('Analysis emoji leaked into Telegram caption');
    if(!str_contains($caption,'📝 Análise:'))throw new RuntimeException('Analysis missing from caption');
    if($fixture['expected']==='bet_builder'){
        foreach($bet['legs'] as $leg){
            if(($leg['odd']??'')!=='')throw new RuntimeException('Bet Builder kept per-selection odd');
        }
        if(str_contains($caption,'📈 Odd:'))throw new RuntimeException('Bet Builder caption exposed per-selection odd');
        if(substr_count($caption,'Odd total:')!==1)throw new RuntimeException('Bet Builder must expose one total odd only');
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
   ||!str_contains($rendererSource,'Reference premium layout used globally for every generated betting card')
   ||!str_contains($rendererSource,'Global Bet Builder rule: each selection has no individual odd')
   ||!str_contains($rendererSource,'Reference-style metrics footer')
   ||!str_contains($rendererSource,'drawPremiumCard')
   ||!str_contains($rendererSource,'Rule: date only. Never append or infer a match time here')){
    throw new RuntimeException('Global premium visual template hooks missing');
}

// Global footer rule: every bookmaker and every card type must publish the
// same Telegram Router identity, with no bookmaker-specific "APOSTAR NA" CTA.
if(!str_contains($rendererSource,"\$label='Telegram Router - Apostas VIP'")
   ||!str_contains($rendererSource,"self::text(\$im,158,\$footerY+104,self::FIXED_STAKE")
   ||!str_contains($rendererSource,"self::text(\$im,158,\$footerY+142,'unidades'")
   ||str_contains($rendererSource,"'APOSTAR NA '")
   ||str_contains($rendererSource,"'APOSTA ENCAMINHADA'")){
    throw new RuntimeException('Global premium footer rule missing');
}

$smartRuntime=file_get_contents(__DIR__.'/../runtime-smart-format.php');
if(!is_string($smartRuntime)
   ||!str_contains($smartRuntime,'Generated smart-card captions keep their structural emojis globally')
   ||!str_contains($smartRuntime,":(string)\$formatted['caption'];")){
    throw new RuntimeException('Structural emoji delivery bypass missing');
}

echo "CARD_MODELS_SMOKE_TESTS_PASSED\n";
