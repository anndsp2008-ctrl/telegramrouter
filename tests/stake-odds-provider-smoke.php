<?php declare(strict_types=1);

require_once __DIR__.'/../app/StakeOddsProvider.php';
require_once __DIR__.'/../app/AdaptiveVipCardRenderer.php';

use App\StakeOddsProvider;
use App\AdaptiveVipCardRenderer;

putenv('STAKE_ODDS_API_ENABLED=0');
$source=[
    'sport'=>'Futebol',
    'match'=>'Palmeiras x Flamengo',
    'league'=>'Brasileirão',
    'market'=>'Total de escanteios',
    'selection'=>'Mais de 8,5 escanteios',
    'odd'=>'1.80',
];
if(StakeOddsProvider::applyToSingle($source)!==$source){
    throw new RuntimeException('Disabled Stake validator must preserve the source bet.');
}

$adaptive=AdaptiveVipCardRenderer::extract([
    'sport'=>'Futebol',
    'bookmaker'=>'Betano',
    'odd'=>'1.80',
    'analysis'=>'Teste de integração.',
    'card_legs'=>json_encode([[
        'sport'=>'Futebol',
        'match'=>'Palmeiras x Flamengo',
        'league'=>'Brasileirão',
        'date'=>'',
        'market'=>'Total de escanteios',
        'selection'=>'Mais de 8,5 escanteios',
        'odd'=>'1.80',
    ]],JSON_UNESCAPED_UNICODE),
],'',false);
if(!is_array($adaptive) || ($adaptive['odd']??'')!=='1.80' || ($adaptive['legs'][0]['odd']??'')!=='1.80'){
    throw new RuntimeException('Adaptive card path must preserve the source odd when Stake validation is disabled.');
}

$fixtures=[
    'fixture'=>[
        [
            'slug'=>'palmeiras-flamengo-123',
            'name'=>'Palmeiras - Flamengo',
            'name'=>'Palmeiras - Flamengo',
            'date'=>1790546400000,
        ],
        [
            'slug'=>'palmeiras-santos-124',
            'name'=>'Palmeiras - Santos',
            'name'=>'Palmeiras - Santos',
            'date'=>1790546400000,
        ],
    ],
];

$fixtureMethod=new ReflectionMethod(StakeOddsProvider::class,'selectFixture');
$fixtureMethod->setAccessible(true);
$fixture=$fixtureMethod->invoke(null,$fixtures,'Palmeiras x Flamengo','Brasileirão','');
if(!is_array($fixture)||($fixture['slug']??'')!=='palmeiras-flamengo-123'){
    throw new RuntimeException('Stake fixture matcher selected the wrong event.');
}

$detail=[
    'fixture'=>[
        'groups'=>[
            [
                'name'=>'totals',
                'markets'=>[
                    [
                        'status'=>'active',
                        'specifiers'=>'total=8.5',
                        'extendedSpecifiers'=>'',
                        'name'=>'Total Corners',
                        'outcomes'=>[
                            ['odds'=>1.95,'active'=>true,'name'=>'Over'],
                            ['odds'=>1.85,'active'=>true,'name'=>'Under'],
                        ],
                    ],
                ],
            ],
        ],
        'swishMarkets'=>[],
    ],
];

$oddMethod=new ReflectionMethod(StakeOddsProvider::class,'selectOdd');
$oddMethod->setAccessible(true);

$odd=$oddMethod->invoke(null,$detail,'Total de escanteios','Mais de 8,5 escanteios');
if($odd!=='1.95'){
    throw new RuntimeException('Stake market/line matcher did not return the expected odd.');
}

$wrongLine=$oddMethod->invoke(null,$detail,'Total de escanteios','Mais de 9,5 escanteios');
if($wrongLine!==null){
    throw new RuntimeException('Stake validator must not reuse an odd from a different line.');
}

$wrongDirection=$oddMethod->invoke(null,$detail,'Total de escanteios','Menos de 8,5 escanteios');
if($wrongDirection!=='1.85'){
    throw new RuntimeException('Stake validator did not preserve Over/Under direction.');
}



$ticketValidator=new ReflectionMethod(StakeOddsProvider::class,'applyToTicket');
$ticketValidator->setAccessible(true);

$doubleTicket=[
    'kind'=>'double',
    'sport'=>'Futebol',
    'odd'=>'1.82',
    'legs'=>[
        [
            'sport'=>'Futebol','match'=>'Palmeiras x Flamengo','league'=>'Brasileirão','date'=>'',
            'market'=>'Total de escanteios','selection'=>'Mais de 8,5 escanteios','odd'=>'1.80',
        ],
        [
            'sport'=>'Futebol','match'=>'Corinthians x Santos','league'=>'Brasileirão','date'=>'',
            'market'=>'Dupla chance','selection'=>'Corinthians ou empate','odd'=>'1.40',
        ],
    ],
];

$legCalls=0;
$validatedDouble=$ticketValidator->invoke(
    null,
    $doubleTicket,
    static function(array $leg) use (&$legCalls): array {
        $legCalls++;
        if($legCalls===1){
            $leg['odd']='1.95';
            return ['bet'=>$leg,'status'=>'validated','changed'=>true,'error'=>''];
        }
        return ['bet'=>$leg,'status'=>'market_or_line_not_found','changed'=>false,'error'=>''];
    }
);

if($legCalls!==2){
    throw new RuntimeException('Every double leg must be checked by Stake.');
}
if(($validatedDouble['legs'][0]['odd']??'')!=='1.95'){
    throw new RuntimeException('Validated Stake odd was not applied to the matching leg.');
}
if(($validatedDouble['legs'][1]['odd']??'')!=='1.40'){
    throw new RuntimeException('Missing Stake market/line must preserve the source leg odd.');
}
if(($validatedDouble['odd']??'')!=='2.73'){
    throw new RuntimeException('Double total odd must be recalculated from final leg odds.');
}

$multipleTicket=[
    'kind'=>'multiple',
    'sport'=>'Futebol',
    'odd'=>'9.99',
    'legs'=>[
        ['match'=>'A x B','market'=>'Mercado 1','selection'=>'Seleção 1','odd'=>'1.50'],
        ['match'=>'C x D','market'=>'Mercado 2','selection'=>'Seleção 2','odd'=>'1.60'],
        ['match'=>'E x F','market'=>'Mercado 3','selection'=>'Seleção 3','odd'=>'1.70'],
    ],
];
$multipleCalls=0;
$validatedMultiple=$ticketValidator->invoke(
    null,
    $multipleTicket,
    static function(array $leg) use (&$multipleCalls): array {
        $multipleCalls++;
        return ['bet'=>$leg,'status'=>'fixture_not_found','changed'=>false,'error'=>''];
    }
);
if($multipleCalls!==3){
    throw new RuntimeException('Every multiple leg must be checked by Stake.');
}
if(($validatedMultiple['odd']??'')!=='4.08'){
    throw new RuntimeException('Multiple total must use final source fallback odds when Stake has no fixture.');
}

$builderTicket=[
    'kind'=>'bet_builder',
    'sport'=>'Futebol',
    'odd'=>'2.05',
    'legs'=>[
        ['match'=>'Palmeiras x Flamengo','market'=>'Total de gols','selection'=>'Mais de 1,5','odd'=>''],
        ['match'=>'Palmeiras x Flamengo','market'=>'Total de escanteios','selection'=>'Mais de 7,5','odd'=>''],
    ],
];
$builderCalls=0;
$validatedBuilder=$ticketValidator->invoke(
    null,
    $builderTicket,
    static function(array $leg) use (&$builderCalls): array {
        $builderCalls++;
        $leg['odd']='1.80';
        return ['bet'=>$leg,'status'=>'validated','changed'=>false,'error'=>''];
    }
);
if($builderCalls!==2){
    throw new RuntimeException('Every Bet Builder selection must be checked by Stake.');
}
if(($validatedBuilder['odd']??'')!=='2.05'){
    throw new RuntimeException('Bet Builder combined source odd must not be replaced by multiplied correlated legs.');
}
foreach($validatedBuilder['legs'] as $leg){
    if(($leg['odd']??'')!==''){
        throw new RuntimeException('Bet Builder must keep per-selection odds hidden after Stake check.');
    }
}

echo "STAKE_ODDS_PROVIDER_TESTS_PASSED\n";
