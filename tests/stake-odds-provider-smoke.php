<?php declare(strict_types=1);

require_once __DIR__.'/../app/StakeOddsProvider.php';

use App\StakeOddsProvider;

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

$fixtures=[
    'fixture'=>[
        [
            'slug'=>'palmeiras-flamengo-123',
            'name'=>'Palmeiras - Flamengo',
            'tournament'=>'Brazil Serie A',
            'competitors'=>['Palmeiras','Flamengo'],
            'startTime'=>1790546400000,
        ],
        [
            'slug'=>'palmeiras-santos-124',
            'name'=>'Palmeiras - Santos',
            'tournament'=>'Brazil Serie A',
            'competitors'=>['Palmeiras','Santos'],
            'startTime'=>1790546400000,
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

echo "STAKE_ODDS_PROVIDER_TESTS_PASSED\n";
