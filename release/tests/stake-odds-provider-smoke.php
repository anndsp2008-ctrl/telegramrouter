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

$womenFixture=[
    'fixture'=>[
        [
            'slug'=>'lyon-chelsea-women-999',
            'name'=>'Olympique Lyonnais Women - Chelsea Women',
            'startTime'=>1790784000000,
            'tournament'=>"UEFA Women's Champions League",
            'competitors'=>['Olympique Lyonnais Women','Chelsea Women'],
        ],
    ],
];
$womenMatch=$fixtureMethod->invoke(
    null,
    $womenFixture,
    'Lyon Feminino x Chelsea Feminino',
    'Liga dos Campeões Feminina',
    ''
);
if(!is_array($womenMatch)||($womenMatch['slug']??'')!=='lyon-chelsea-women-999'){
    throw new RuntimeException('Stake women-team translated-name matching failed.');
}

$schedulePayload=[
    'schedule'=>[
        [
            'date'=>1790784000000,
            'fixture'=>[
                [
                    'slug'=>'lyon-chelsea-women-schedule',
                    'name'=>'Olympique Lyonnais Women - Chelsea Women',
                    'tournamentId'=>'women-ucl',
                ],
            ],
        ],
    ],
];
$scheduleMatch=$fixtureMethod->invoke(
    null,
    $schedulePayload,
    'Lyon Feminino x Chelsea Feminino',
    'Liga dos Campeões Feminina',
    ''
);
if(!is_array($scheduleMatch)||($scheduleMatch['slug']??'')!=='lyon-chelsea-women-schedule'){
    throw new RuntimeException('Stake schedule fallback fixture extraction failed.');
}

$aliasFixture=[
    'fixture'=>[
        [
            'slug'=>'lyon-chelsea-alias',
            'name'=>'Olympique Lyonnais Women - Chelsea Women',
            'startTime'=>1790895600000,
            'tournament'=>"UEFA Women's Champions League",
            'competitors'=>['Olympique Lyonnais Women','Chelsea Women'],
        ],
        [
            'slug'=>'lyon-chelsea-senior',
            'name'=>'Olympique Lyonnais - Chelsea',
            'startTime'=>1790895600000,
            'tournament'=>'Club Friendly',
            'competitors'=>['Olympique Lyonnais','Chelsea'],
        ],
    ],
];
$aliasMatch=$fixtureMethod->invoke(
    null,
    $aliasFixture,
    'Lyon Feminino x Chelsea Feminino',
    'Liga dos Campeões Feminina',
    '02/10/2026'
);
if(!is_array($aliasMatch)||($aliasMatch['slug']??'')!=='lyon-chelsea-alias'){
    throw new RuntimeException('Stake alias/gender-aware fixture matching failed.');
}

$timezoneFixture=[
    'fixture'=>[
        [
            'slug'=>'timezone-fixture',
            'name'=>'Palmeiras - Flamengo',
            'startTime'=>1790982000000,
            'tournament'=>'Brasileirao',
            'competitors'=>['Palmeiras','Flamengo'],
        ],
    ],
];
$timezoneMatch=$fixtureMethod->invoke(
    null,
    $timezoneFixture,
    'Palmeiras x Flamengo',
    'Brasileirão',
    '02/10/2026'
);
if(!is_array($timezoneMatch)||($timezoneMatch['slug']??'')!=='timezone-fixture'){
    throw new RuntimeException('Stake +/-1 day timezone tolerance failed.');
}

$womenLeagueFixture=[
    'fixture'=>[
        [
            'slug'=>'benfica-bayern-women',
            'name'=>'Benfica Women - Bayern Munich Women',
            'startTime'=>1790895600000,
            'tournament'=>"UEFA Women's Champions League",
            'competitors'=>['Benfica Women','Bayern Munich Women'],
        ],
        [
            'slug'=>'benfica-bayern-senior',
            'name'=>'Benfica - Bayern Munich',
            'startTime'=>1790895600000,
            'tournament'=>'Club Friendly',
            'competitors'=>['Benfica','Bayern Munich'],
        ],
    ],
];
$womenLeagueMatch=$fixtureMethod->invoke(
    null,
    $womenLeagueFixture,
    'Benfica vs Bayern Munich',
    "UEFA Women's Champions League",
    ''
);
if(!is_array($womenLeagueMatch)||($womenLeagueMatch['slug']??'')!=='benfica-bayern-women'){
    throw new RuntimeException('Women competition must force matching against women teams even when source match omits Women/Feminino.');
}

$categoryHint=new ReflectionMethod(StakeOddsProvider::class,'stakeCategoryHint');
$categoryHint->setAccessible(true);
if($categoryHint->invoke(null,'UEFA Champions League')!=='international'){
    throw new RuntimeException('Champions League must prioritize Stake International category.');
}
if($categoryHint->invoke(null,'Primeira Liga')!=='portugal'){
    throw new RuntimeException('Primeira Liga must prioritize Stake Portugal category.');
}
if($categoryHint->invoke(null,'Bundesliga')!=='germany'){
    throw new RuntimeException('Bundesliga must prioritize Stake Germany category.');
}

$rankCategories=new ReflectionMethod(StakeOddsProvider::class,'rankStakeCategories');
$rankCategories->setAccessible(true);
$ranked=$rankCategories->invoke(null,[
    ['slug'=>'germany-1','name'=>'Germany'],
    ['slug'=>'international-1','name'=>'International'],
    ['slug'=>'portugal-1','name'=>'Portugal'],
],'UEFA Champions League');
if(($ranked[0]['slug']??'')!=='international-1'){
    throw new RuntimeException('International Stake category was not prioritized for Champions League.');
}

$tournamentHint=new ReflectionMethod(StakeOddsProvider::class,'stakeTournamentHint');
$tournamentHint->setAccessible(true);
if($tournamentHint->invoke(null,"UEFA Women's Champions League",'Benfica Feminino vs Bayern Munich Feminino')!=='womenschampionsleague'){
    throw new RuntimeException('Stake women Champions League tournament hint failed.');
}

$rankTournaments=new ReflectionMethod(StakeOddsProvider::class,'rankStakeTournaments');
$rankTournaments->setAccessible(true);
$rankedTournaments=$rankTournaments->invoke(null,[
    ['slug'=>'uefa-champions-league','name'=>'UEFA Champions League'],
    ['slug'=>'uefa-womens-champions-league','name'=>"UEFA Women's Champions League"],
    ['slug'=>'club-friendlies','name'=>'Club Friendlies'],
],"UEFA Women's Champions League",'Benfica Feminino vs Bayern Munich Feminino');
if(($rankedTournaments[0]['slug']??'')!=='uefa-womens-champions-league'){
    throw new RuntimeException('Stake women tournament ranking did not prioritize the women competition.');
}

$womenTournamentFixture=[
    'fixture'=>[
        [
            'slug'=>'benfica-bayern-women-context',
            'name'=>'SL Benfica - Bayern Munich',
            'competitors'=>['SL Benfica','Bayern Munich'],
            '_stake_tournament_context'=>"UEFA Women's Champions League",
        ],
        [
            'slug'=>'benfica-bayern-men',
            'name'=>'SL Benfica - Bayern Munich',
            'competitors'=>['SL Benfica','Bayern Munich'],
            '_stake_tournament_context'=>'UEFA Champions League',
        ],
    ],
];
$womenTournamentMatch=$fixtureMethod->invoke(
    null,
    $womenTournamentFixture,
    'Benfica vs Bayern de Munique Feminino',
    "Liga dos Campeões Feminina",
    ''
);
if(!is_array($womenTournamentMatch)||($womenTournamentMatch['slug']??'')!=='benfica-bayern-women-context'){
    throw new RuntimeException('Stake women tournament context must match bare team labels and translated Bayern de Munique.');
}

$selectionScoreMethod=new ReflectionMethod(StakeOddsProvider::class,'selectionScore');
$selectionScoreMethod->setAccessible(true);
$aliasSelectionScore=$selectionScoreMethod->invoke(
    null,
    'bayernmunich',
    'bayernmunich',
    'Bayern de Munique Feminino',
    'Bayern Munich'
);
if((float)$aliasSelectionScore<0.94){
    throw new RuntimeException('Stake translated/gender team selection alias matching failed.');
}

$canonicalSelectionMethod=new ReflectionMethod(StakeOddsProvider::class,'canonicalSelection');
$canonicalSelectionMethod->setAccessible(true);
if($canonicalSelectionMethod->invoke(null,'Bayern de Munique Feminino')!=='bayernmunich'){
    throw new RuntimeException('Stake selection canonicalization must map Bayern de Munique Feminino to Bayern Munich.');
}

$comboDetail=[
    'data'=>[
        'fixture'=>[
            'slug'=>'benfica-bayern-women-combo',
            'groups'=>[
                [
                    'name'=>'specials',
                    'markets'=>[
                        [
                            'status'=>'active',
                            'name'=>'Match Winner & Both Teams To Score',
                            'specifiers'=>'',
                            'outcomes'=>[
                                ['name'=>'Bayern Munich & Yes','odds'=>1.87,'active'=>true],
                                ['name'=>'SL Benfica & Yes','odds'=>6.20,'active'=>true],
                                ['name'=>'Bayern Munich & No','odds'=>2.40,'active'=>true],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
$selectOddMethod=new ReflectionMethod(StakeOddsProvider::class,'selectOdd');
$selectOddMethod->setAccessible(true);
$comboOdd=$selectOddMethod->invoke(
    null,
    $comboDetail,
    'Vencedor da partida e ambas as equipes marcam',
    'Bayern de Munique Feminino vence e ambas as equipes marcam'
);
if($comboOdd!=='1.87'){
    throw new RuntimeException('Stake combo market Winner + BTTS alias matching failed: '.var_export($comboOdd,true));
}

$marketRowsFromPayload=new ReflectionMethod(StakeOddsProvider::class,'marketRowsFromPayload');
$marketRowsFromPayload->setAccessible(true);
$comboRows=$marketRowsFromPayload->invoke(null,$comboDetail);
if(!is_array($comboRows)||count($comboRows)!==3){
    throw new RuntimeException('Stake nested fixture market payload unwrapping failed.');
}

$listWrapped=[
    'fixture'=>[
        [
            'slug'=>'wrapped',
            'groups'=>$comboDetail['data']['fixture']['groups'],
        ],
    ],
];
$listRows=$marketRowsFromPayload->invoke(null,$listWrapped);
if(!is_array($listRows)||count($listRows)!==3){
    throw new RuntimeException('Stake list-wrapped fixture market payload unwrapping failed.');
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

$genericMarketScore=new ReflectionMethod(StakeOddsProvider::class,'marketScore');
$genericMarketScore->setAccessible(true);
$cornersScore=$genericMarketScore->invoke(null,'totalcorners','totalscorners');
if(!is_float($cornersScore) && !is_int($cornersScore)){
    throw new RuntimeException('Stake market similarity type changed unexpectedly.');
}
if((float)$cornersScore<0.62){
    throw new RuntimeException('Team-name normalization leaked into market matching.');
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

$truncateTicket=[
    'kind'=>'double',
    'sport'=>'Futebol',
    'odd'=>'9.99',
    'legs'=>[
        ['match'=>'Jogo 1','market'=>'Mercado 1','selection'=>'Seleção 1','odd'=>'1.30'],
        ['match'=>'Jogo 2','market'=>'Mercado 2','selection'=>'Seleção 2','odd'=>'1.35'],
    ],
];
$truncateCalls=0;
$truncatedDouble=$ticketValidator->invoke(
    null,
    $truncateTicket,
    static function(array $leg) use (&$truncateCalls): array {
        $truncateCalls++;
        return ['bet'=>$leg,'status'=>'fixture_not_found','changed'=>false,'error'=>''];
    }
);
if($truncateCalls!==2){
    throw new RuntimeException('Both legs must be checked before truncating the final double odd.');
}
if(($truncatedDouble['odd']??'')!=='1.75'){
    throw new RuntimeException('1.30 x 1.35 must publish as 1.75 by truncation.');
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
