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

$apiFootballSelector=new ReflectionMethod(AdaptiveVipCardRenderer::class,'selectApiFootballTeamLogo');
$apiFootballSelector->setAccessible(true);

$nationalPayload=[
    'response'=>[
        ['team'=>[
            'id'=>777,'name'=>'Turkey','country'=>'Turkey','national'=>true,
            'logo'=>'https://media.api-sports.io/football/teams/777.png'
        ]],
        ['team'=>[
            'id'=>778,'name'=>'Turkey Club','country'=>'Turkey','national'=>false,
            'logo'=>'https://media.api-sports.io/football/teams/778.png'
        ]],
    ],
];
$nationalLogo=$apiFootballSelector->invoke(null,$nationalPayload,'Turquia','UEFA');
if($nationalLogo!=='https://media.api-sports.io/football/teams/777.png'){
    throw new RuntimeException('API-Football national identity selection failed');
}

$clubPayload=[
    'response'=>[
        ['team'=>[
            'id'=>100,'name'=>'León','country'=>'Mexico','national'=>false,
            'logo'=>'https://media.api-sports.io/football/teams/100.png'
        ]],
        ['team'=>[
            'id'=>101,'name'=>'León','country'=>'Nicaragua','national'=>false,
            'logo'=>'https://media.api-sports.io/football/teams/101.png'
        ]],
    ],
];
$clubLogo=$apiFootballSelector->invoke(null,$clubPayload,'Club León','Liga MX');
if($clubLogo!=='https://media.api-sports.io/football/teams/100.png'){
    throw new RuntimeException('API-Football league/country disambiguation failed');
}

$ambiguousPayload=[
    'response'=>[
        ['team'=>[
            'id'=>200,'name'=>'United','country'=>'England','national'=>false,
            'logo'=>'https://media.api-sports.io/football/teams/200.png'
        ]],
        ['team'=>[
            'id'=>201,'name'=>'United','country'=>'England','national'=>false,
            'logo'=>'https://media.api-sports.io/football/teams/201.png'
        ]],
    ],
];
if($apiFootballSelector->invoke(null,$ambiguousPayload,'United','Premier League')!==null){
    throw new RuntimeException('Ambiguous API-Football identity must fall back');
}

$identityResolver=new ReflectionMethod(AdaptiveVipCardRenderer::class,'resolveParticipantIdentity');
$identityResolver->setAccessible(true);
$turkeyIdentity=$identityResolver->invoke(null,'Turquia','Liga das Nações','football');
$italyIdentity=$identityResolver->invoke(null,'Itália','Liga das Nações','football');
if(($turkeyIdentity['kind']??'')!=='flag'||(($turkeyIdentity['spec']['type']??'')!=='turkey')){
    throw new RuntimeException('Turkey national flag resolution failed');
}
if(($italyIdentity['kind']??'')!=='flag'||(($italyIdentity['spec']['type']??'')!=='v3')){
    throw new RuntimeException('Italy national flag resolution failed');
}

$flagGeometryDraw=new ReflectionMethod(AdaptiveVipCardRenderer::class,'drawFlagSized');
$flagGeometryDraw->setAccessible(true);
$italyProbe=imagecreatetruecolor(80,80);
if($italyProbe===false)throw new RuntimeException('Italy flag geometry canvas failed');
$flagGeometryDraw->invoke(null,$italyProbe,40,40,$italyIdentity['spec'],44);
// For a 44px identity the rectangular flag begins around x=18/y=26.
// Its upper-left pixel must be green, proving there is no circular mask
// and no white/gray synthetic border around the flag.
$italyCorner=imagecolorsforindex($italyProbe,imagecolorat($italyProbe,19,27));
unset($italyProbe);
if(!is_array($italyCorner)
   ||($italyCorner['green']??0)<70
   ||($italyCorner['red']??255)>80
   ||($italyCorner['blue']??255)>140){
    throw new RuntimeException('National flag must be rectangular and borderless');
}

$turkeyFlagDraw=new ReflectionMethod(AdaptiveVipCardRenderer::class,'drawFlagSized');
$turkeyFlagDraw->setAccessible(true);
$turkeyProbe=imagecreatetruecolor(80,80);
if($turkeyProbe===false)throw new RuntimeException('Turkey flag probe canvas failed');
$turkeyFlagDraw->invoke(null,$turkeyProbe,40,40,$turkeyIdentity['spec'],44);
$turkeyProbePath=sys_get_temp_dir().'/tmr-turkey-flag-'.bin2hex(random_bytes(6)).'.png';
if(!imagepng($turkeyProbe,$turkeyProbePath))throw new RuntimeException('Turkey flag probe PNG failed');
unset($turkeyProbe);
$turkeyProbeSize=getimagesize($turkeyProbePath);
@unlink($turkeyProbePath);
if(!is_array($turkeyProbeSize)||($turkeyProbeSize['mime']??'')!=='image/png'){
    throw new RuntimeException('Turkey flag direct draw failed');
}

$nationalFixture=[
    'bookmaker'=>'Betano','bet_kind'=>'multiple','selections_count'=>'2',
    'sport'=>'Futebol','odd'=>'1.70',
    'analysis'=>'Dupla internacional usada para validar as bandeiras nacionais.',
    'card_legs'=>json_encode([
        ['sport'=>'Futebol','match'=>'Turquia x Itália','league'=>'Liga das Nações','date'=>'28/09/2026','market'=>'Total de escanteios','selection'=>'Mais de 3,5','odd'=>'1.30'],
        ['sport'=>'Futebol','match'=>'Bélgica x França','league'=>'Liga das Nações','date'=>'28/09/2026','market'=>'Dupla chance','selection'=>'França ou empate','odd'=>'1.31']
    ],JSON_UNESCAPED_UNICODE)
];
$nationalBet=AdaptiveVipCardRenderer::extract($nationalFixture,'',false);
if($nationalBet===null)throw new RuntimeException('National flag fixture extraction failed');
$nationalImage=AdaptiveVipCardRenderer::render($nationalBet);
if($nationalImage===null)throw new RuntimeException('National flag fixture render failed');
$nationalSize=getimagesize($nationalImage);
@unlink($nationalImage);
if(!is_array($nationalSize)||($nationalSize['mime']??'')!=='image/png'){
    throw new RuntimeException('National flag fixture PNG invalid');
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
if(($unknownBet['bookmaker']??'')!=='Stake'||($unknownBet['bookmaker_key']??'')!=='stake'){
    throw new RuntimeException('Empty bookmaker must be treated as Stake');
}

foreach(['Casa Desconhecida','Casa desconhecida','desconhecida','unknown','N/A','-'] as $unknownLabel){
    $unknownCase=$fixtures[0]['data'];
    $unknownCase['bookmaker']=$unknownLabel;
    $normalizedUnknown=AdaptiveVipCardRenderer::extract($unknownCase,'',false);
    if(($normalizedUnknown['bookmaker']??'')!=='Stake'||($normalizedUnknown['bookmaker_key']??'')!=='stake'){
        throw new RuntimeException('Unknown bookmaker label must be treated as Stake: '.$unknownLabel);
    }
}

$legacyUnknown=$unknownBet;
$legacyUnknown['bookmaker']='Casa desconhecida';
$legacyUnknown['bookmaker_key']='unknown';
$legacyCaption=AdaptiveVipCardRenderer::caption($legacyUnknown);
if(!str_contains($legacyCaption,'• Stake')||str_contains($legacyCaption,'Casa desconhecida')){
    throw new RuntimeException('Legacy unknown bookmaker output must be rendered as Stake');
}

$rendererSource=file_get_contents(__DIR__.'/../app/AdaptiveVipCardRenderer.php');
if(!is_string($rendererSource)
   ||!str_contains($rendererSource,'drawPremiumCard')
   ||!str_contains($rendererSource,'private static function drawSportIcon')
   ||!str_contains($rendererSource,'private static function resolvePairIdentity')
   ||!str_contains($rendererSource,'TMR_ADAPTIVE_CARD_RENDER_RETRY_NAMES_ONLY')
   ||!str_contains($rendererSource,'TMR_ADAPTIVE_CARD_NAMES_ONLY_READY')
   ||!str_contains($rendererSource,'private static function resolveParticipantIdentity')
   ||!str_contains($rendererSource,'private static function officialTeamBadgePath')
   ||!str_contains($rendererSource,"private const SPORTSDB_FREE_KEY='123'")
   ||!str_contains($rendererSource,'search_all_teams.php')
   ||!str_contains($rendererSource,'private static function sportsDbFlagPath')
   ||!str_contains($rendererSource,'private static function theSportsDbCountryName')
   ||!str_contains($rendererSource,"foreach([64,32,16] as \$size)")
   ||!str_contains($rendererSource,"'type'=>'malta'")
   ||!str_contains($rendererSource,"'type'=>'wales'")
   ||!str_contains($rendererSource,"'type'=>'greece'")
   ||!str_contains($rendererSource,"'type'=>'liechtenstein'")
   ||!str_contains($rendererSource,'private static function drawResolvedIdentity')
   ||!str_contains($rendererSource,'private static function drawLeftAlignedMatchup')
   ||!str_contains($rendererSource,'always left-aligned')
   ||!str_contains($rendererSource,'self::drawLeftAlignedMatchup(
                        $im,
                        145,')
   ||!str_contains($rendererSource,'self::drawLeftAlignedMatchup(
                                $im,
                                $textX,')
   ||!str_contains($rendererSource,'private static function drawFlagSized')
   ||!str_contains($rendererSource,'National identities use a real flag silhouette: rectangular, borderless')
   ||!str_contains($rendererSource,'$flagW=$size')
   ||!str_contains($rendererSource,'Team/club artwork is rendered as-is: transparent background')
   ||!str_contains($rendererSource,'private static function drawStar')
   ||!str_contains($rendererSource,'private static function bookmakerForOutput')
   ||!str_contains($rendererSource,'any unidentified bookmaker is treated as Stake')
   ||!str_contains($rendererSource,'private static function selectionParticipant')
   ||!str_contains($rendererSource,'private static function apiFootballTeamAssetPath')
   ||!str_contains($rendererSource,'TMR_NATIONAL_FLAG_LOCAL')
   ||!str_contains($rendererSource,"['type'=>'turkey'")
   ||!str_contains($rendererSource,'private static function selectApiFootballTeamLogo')
   ||!str_contains($rendererSource,'SportsApiIntegration::apiFootballKey()')
   ||!str_contains($rendererSource,'https://v3.football.api-sports.io/teams?search=')
   ||!str_contains($rendererSource,"'x-apisports-key: '.\$apiKey")
   ||!str_contains($rendererSource,"'media.api-sports.io'")
   ||!str_contains($rendererSource,'$marketSelectionGap=15')
   ||!str_contains($rendererSource,'private const MULTI_DATE_BLOCK_ADVANCE=46')
   ||!str_contains($rendererSource,'private const MULTI_METADATA_MARKET_GAP=8')
   ||!str_contains($rendererSource,'$ty+=self::MULTI_DATE_BLOCK_ADVANCE')
   ||!str_contains($rendererSource,'$ty+=self::MULTI_METADATA_MARKET_GAP')
   ||!str_contains($rendererSource,"return str_replace(',','.',\$odd)")){
    throw new RuntimeException('Global premium renderer hooks missing');
}
if(str_contains($rendererSource,'private static function drawClubCrest')
   ||str_contains($rendererSource,'Premium shield fallback')
   ||str_contains($rendererSource,"imagefilledellipse(\$im,\$cx,\$cy,\$size+4,\$size+4")){
    throw new RuntimeException('Synthetic/circular identity border must stay disabled');
}
if(str_contains($rendererSource,'flagcdn.com')
   ||str_contains($rendererSource,'searchteams.php?t=')){
    throw new RuntimeException('Identity provider must stay on TheSportsDB free endpoints');
}
if(!str_contains($rendererSource,'National teams are resolved before club lookup.')
   ||!str_contains($rendererSource,"\$country=self::theSportsDbCountryName(\$participant)")
   ||!str_contains($rendererSource,"\$localFlag=self::flagSpec(\$participant)")
   ||!str_contains($rendererSource,"\$flagPath=self::sportsDbFlagPath(\$country)")){
    throw new RuntimeException('National flags must prefer the GD-compatible local renderer before remote fallback');
}
if(str_contains($rendererSource,"self::text(\$im,446,\$top+72,'x'")
   ||str_contains($rendererSource,"self::text(\$im,\$textX+300,\$ty,'x'")
   ||str_contains($rendererSource,'drawProportionalMatchup(')
   ||str_contains($rendererSource,'$centerX-(int)floor($total/2)')){
    throw new RuntimeException('Centered/fixed matchup layout reintroduced');
}
if(str_contains($rendererSource,'curl_close(')
   ||str_contains($rendererSource,'imagedestroy(')){
    throw new RuntimeException('Deprecated PHP 8.5 resource cleanup leaked into premium renderer');
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
