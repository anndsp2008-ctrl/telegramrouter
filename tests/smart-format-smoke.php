<?php declare(strict_types=1);
require __DIR__.'/../app/PurePngVipCardRenderer.php';
require __DIR__.'/../app/VipCardRenderer.php';
require __DIR__.'/../app/SmartFormatting.php';
use App\VipCardRenderer;
use App\SmartFormatting;
$bet=['match'=>'Venezia × Lazio','league'=>'Itália • Série A',
  'market'=>'Resultado final da partida (1X2)','selection'=>'Vitória da Lazio',
  'odd'=>'2,00','time'=>'19h45','day'=>'Sábado',
  'analysis'=>'A Lazio chega invicta para enfrentar o Venezia, último colocado, buscando manter seu bom início de temporada.'];
$text=SmartFormatting::asText($bet,true);
foreach(['Venezia × Lazio','Vitória da Lazio','A Lazio chega invicta'] as $required){
  if(!str_contains($text,$required))throw new RuntimeException('Missing original detail: '.$required);
}
if(!str_contains(SmartFormatting::signature(),'⚡ TelegramRouter • Aposta encaminhada'))throw new RuntimeException('Signature mismatch');
// Sentence initials, not Title Case: keep internal case, proper names, numeric
// odds, URLs and paragraph structure intact. Format card text and image identically.
$caseExamples=[
    ['o real madrid enfrenta o barcelona. a equipe busca a vitória.',
     'O real madrid enfrenta o barcelona. A equipe busca a vitória.'],
    ["  “uma análise.” outra frase!\n• próxima linha? sim.",
     "  “Uma análise.” Outra frase!\n• Próxima linha? Sim."],
    ['odd 1.5 e mercado over 8,5. a tip é de R$ 100,00. https://site.com/a?odd=1.5',
     'Odd 1.5 e mercado over 8,5. A tip é de R$ 100,00. https://site.com/a?odd=1.5'],
    ['a GPT não altera NBA, PIX, @tipster nem https://site.com/URL.',
     'A GPT não altera NBA, PIX, @tipster nem https://site.com/URL.'],
    ["⚽ futebol\n\n📝 análise original.",
     "⚽ Futebol\n\n📝 Análise original."]
];
foreach($caseExamples as [$original,$expected]){
    $actual=SmartFormatting::capitalizeSentences($original);
    if($actual!==$expected||SmartFormatting::capitalizeSentences($actual)!==$expected)
        throw new RuntimeException('Sentence case regression: '.bin2hex($original));
}
$lowerTip=array_merge($bet,[
    'match'=>'real madrid x barcelona',
    'market'=>'resultado final. vitória ou empate',
    'selection'=>'vitória do real madrid',
    'analysis'=>"o time chega motivado. a odd é 1.75 e a seleção segue a mesma.\nnovo parágrafo.",
    'odd'=>'1.75', 'stake'=>'5', 'stake_amount'=>'R$ 200,00'
]);
$lowerOriginal=$lowerTip;
$lowerCard=SmartFormatting::cardView($lowerTip);
$lowerText=SmartFormatting::asText($lowerCard,true);
foreach(['Real madrid x barcelona','Resultado final. Vitória ou empate',
    'Vitória do real madrid','O time chega motivado. A odd é 1.75',
    "\nNovo parágrafo.",'Odd: 1.75','Stake: 10'] as $fragment){
    if(!str_contains($lowerText,$fragment))
        throw new RuntimeException('AI tip sentence formatting missing expected fragment: '.$fragment);
}
if($lowerCard['analysis']!=="O time chega motivado. A odd é 1.75 e a seleção segue a mesma.\nNovo parágrafo." ||
   $lowerCard['stake']!=='10' || $lowerTip!==$lowerOriginal ||
   $lowerTip['stake_amount']!=='R$ 200,00')
    throw new RuntimeException('Sentence normalization changed source or betting values');
echo "SMART_FORMAT_SENTENCE_CASE_TESTS_PASSED\n";
// The source may carry financial marketing prose even after setting Stake 10.
// Keep sports-only sentences, never repeat source-channel stake, wager amount,
// expected money return or bankroll references inside the AI analysis.
$incomingAnalysis='A aposta é uma simples aposta de ambos os times marcando, com Fulham e Manchester United se enfrentando na Premier League. '
    .'A probabilidade de ambos os times marcarem é de 1.50, o que significa que a aposta tem uma chance de 66,67% de ser vencedora. '
    .'A aposta é feita com responsabilidade, com um stake de 2 e um valor de aposta de €2. '
    .'O potencial de retorno é de €3.00, caso a aposta seja vencedora.';
$expectedAnalysis='A aposta é uma simples aposta de ambos os times marcando, com Fulham e Manchester United se enfrentando na Premier League. '
    .'A probabilidade de ambos os times marcarem é de 1.50, o que significa que a aposta tem uma chance de 66,67% de ser vencedora.';
if(SmartFormatting::sanitizeAnalysis($incomingAnalysis)!==$expectedAnalysis ||
   SmartFormatting::sanitizeAnalysis($expectedAnalysis)!==$expectedAnalysis)
    throw new RuntimeException('Financial analysis should be omitted without changing sports-only sentences');
$moneyAnalysisExamples=[
    ["A equipe pressiona. Stake 2 e aposta de €2. O ataque tem chances.",'A equipe pressiona. O ataque tem chances.'],
    ["O time cria chances. Valor a ser apostado: R$ 200,00. Mercado de gols mantido.",'O time cria chances. Mercado de gols mantido.'],
    ["A equipe joga no ataque.\nRetorno potencial de 3 euros.\nO adversário sofre gols.",'A equipe joga no ataque.'."\n".'O adversário sofre gols.'],
    ["Fulham segue pressionando. Sugestão: 2 unidades de stake; bankroll EUR 100. Jogo equilibrado.",'Fulham segue pressionando. Jogo equilibrado.'],
    ['O time cria chances. Amount staked USD 20 and potential return 35 dollars. Mercado mantém-se.', 'O time cria chances. Mercado mantém-se.'],
    ['A odd é 1.50 e a chance informada é 66,67%. O time pressiona pelo gol.', 'A odd é 1.50 e a chance informada é 66,67%. O time pressiona pelo gol.']
];
foreach($moneyAnalysisExamples as [$source,$expected]){
    if(SmartFormatting::sanitizeAnalysis($source)!==$expected)
        throw new RuntimeException('Analysis financial scrub regression: '.bin2hex($source));
}
$tipWithMoney=array_merge($bet,[
    'analysis'=>$incomingAnalysis,'stake'=>'2','odd'=>'1.50',
    'stake_amount'=>'€2','potential_return'=>'€3.00'
]);
$originalTipWithMoney=$tipWithMoney;
$cardWithoutMoney=SmartFormatting::cardView($tipWithMoney);
foreach([$cardWithoutMoney, $tipWithMoney] as $formatTip){
    foreach([true,false] as $translated){
        $message=SmartFormatting::asText($formatTip,$translated);
        if(!str_contains($message,'Stake: 10') ||
           !str_contains($message,'Odd: 1.50') ||
           !str_contains($message,'Fulham e Manchester United') ||
           str_contains($message,'A aposta é feita com responsabilidade') ||
           !str_contains($message,'A probabilidade de ambos os times'))
            throw new RuntimeException('Sports tip or fixed stake was lost during financial analysis scrub');
        $analysisTail=explode('📝 ', $message,2)[1]??'';
        foreach(['stake de 2','€2','€3.00','retorno','valor de aposta'] as $forbidden){
            if(mb_stripos($analysisTail,$forbidden,0,'UTF-8')!==false)
                throw new RuntimeException('Source financial advice leaked into formatted analysis');
        }
    }
}
if($cardWithoutMoney['analysis']!==$expectedAnalysis ||
   $tipWithMoney!==$originalTipWithMoney || $cardWithoutMoney['stake']!=='10' ||
   isset($cardWithoutMoney['stake_amount']))
    throw new RuntimeException('Scrubbing altered source or card display boundaries');
echo "SMART_FORMAT_NO_SOURCE_FINANCIAL_ANALYSIS_TESTS_PASSED\n";

// Every AI-formatted tip publishes Stake 10, even if it was absent, malformed
// or supplied as a different suggested unit amount by the source channel.
foreach([[],['stake'=>''],['stake'=>'2'],['stake'=>'6/10'],['stake'=>'999']] as $input){
    $fixture=array_merge($bet,$input);
    foreach([true,false] as $translated){
        $formatted=SmartFormatting::asText($fixture,$translated);
        if(substr_count($formatted,"Stake: 10")!==1 ||
           str_contains($formatted,"Stake: 6/10") ||
           str_contains($formatted,"Stake: 999"))
            throw new RuntimeException('Fixed stake missing or original stake was forwarded');
    }
    $view=SmartFormatting::cardView($fixture);
    if(($view['stake']??'')!=='10' || ($view['match']??'')!==$bet['match'])
        throw new RuntimeException('Card view did not apply fixed Stake 10');
}
$moneyFixture=array_merge($bet,['stake'=>'3','stake_amount'=>'R$ 200,00',
    'potential_return'=>'R$ 400,00','odd'=>'2,00']);
$moneyText=SmartFormatting::asText($moneyFixture,true);
if(!str_contains($moneyText,'Stake: 10') ||
   !str_contains($moneyText,'Valor apostado: R$ 200,00') ||
   !str_contains($moneyText,'Odd: 2,00'))
    throw new RuntimeException('Fixed stake overwrote receipt money or odds');
echo "SMART_FORMAT_FIXED_STAKE_10_TESTS_PASSED\\n";
// Regression: the author's complete long analysis must never be reduced to 480 chars.
$longBet=$bet;
$longBet['analysis']=str_repeat('Análise completa do autor, mantida na mensagem sem cortes. ',32).'FIM_DA_ANALISE_ORIGINAL';
$longText=SmartFormatting::asText($longBet,true);
if(!str_contains($longText,'FIM_DA_ANALISE_ORIGINAL')||mb_strlen($longText,'UTF-8')<=480){
  throw new RuntimeException('Long original analysis was truncated');
}
if((int)(strlen(mb_convert_encoding($longText,'UTF-16LE','UTF-8'))/2)<=1024){
  throw new RuntimeException('Long caption fixture is not over Telegram media limit');
}
putenv('VIP_CARD_FORCE_PURE=1');
$image=VipCardRenderer::render($bet);
if($image===null)throw new RuntimeException('VIP rendering unavailable even with GD and DejaVu fonts');
$size=getimagesize($image);
if(!is_array($size)||$size['mime']!=='image/png'||$size[0]!==1080)throw new RuntimeException('Invalid VIP PNG');
@unlink($image);
// New opt-in text+receipt scenario: correct arithmetic without fabricated date.
$liveBet=[
 'sport'=>'FUTEBOL','status'=>'AO VIVO','match'=>'Athletic Bilbao × Alavés',
 'league'=>'ES Espanha • Primeira Divisão','market'=>'Resultado da partida',
 'selection'=>'Vitória do Athletic Bilbao','odd'=>'1,60','stake'=>'6/10',
 'time'=>'16h15','stake_amount'=>'2.000,00 €','potential_return'=>'3.200,00 €',
 'analysis'=>''
];
$profit=SmartFormatting::calculatePotentialProfit($liveBet['stake_amount'],$liveBet['potential_return']);
if($profit!=='1.200,00 €')throw new RuntimeException('Incorrect deterministic slip profit');
$liveBet['potential_profit']=$profit;
if(SmartFormatting::calculatePotentialProfit('2.000,00 €','3.200,00 R$')!=='')
    throw new RuntimeException('Mixed currencies must not produce inferred profit');
if(SmartFormatting::calculatePotentialProfit('R$ 2.000,00','R$ 3.200,00')!=='R$ 1.200,00')
    throw new RuntimeException('BRL profit parsing failed');
if(SmartFormatting::calculatePotentialProfit('2,000.00 USD','3,200.00 USD')!=='1.200,00 USD')
    throw new RuntimeException('US-formatted amount parsing failed');
if(SmartFormatting::calculatePotentialProfit('100,00 €','50,00 €')!=='')
    throw new RuntimeException('Negative returns must not produce misleading profit');
$liveText=SmartFormatting::asText($liveBet,true);
foreach(['AO VIVO','16h15','2.000,00 €','3.200,00 €','1.200,00 €'] as $required)
    if(!str_contains($liveText,$required))throw new RuntimeException('Missing slip detail: '.$required);
foreach(['Sábado','UTC','2026-'] as $forbidden)
    if(str_contains($liveText,$forbidden))throw new RuntimeException('Invented date or timezone in slip');
$liveImage=VipCardRenderer::render($liveBet);
if($liveImage===null)throw new RuntimeException('Live slip render unavailable');
$liveSize=getimagesize($liveImage);
if(!is_array($liveSize)||$liveSize['mime']!=='image/png'||$liveSize[0]!==1080)
    throw new RuntimeException('Invalid live slip PNG');
@unlink($liveImage);
putenv('VIP_CARD_FORCE_PURE=0');
$liveGdImage=VipCardRenderer::render($liveBet);
if($liveGdImage===null)throw new RuntimeException('Live slip GD or fallback render unavailable');
$liveGdSize=getimagesize($liveGdImage);
if(!is_array($liveGdSize)||$liveGdSize['mime']!=='image/png'||$liveGdSize[0]!==1080)
    throw new RuntimeException('Invalid live slip GD/fallback PNG');
@unlink($liveGdImage);
// Approved APOSTA DO DIA template is card-only: retain analysis and exclude
// bookmaker, stake amounts, returns, profits and source-tip time.
// Preserve explicit live status only to drive its visual badge.
// Non-card text/legend mode continues to expose the original extracted fields.
$cardFixture=$liveBet;
$cardFixture['analysis']='Análise original integral da tip, sem alterar o argumento do autor.';
$cardFixture['bookmaker']='WINAMAX';
$cardFixture['day']='Sábado';
$cardView=SmartFormatting::cardView($cardFixture);
$cardText=SmartFormatting::asText($cardView,true);
foreach(['Athletic Bilbao × Alavés','Vitória do Athletic Bilbao','1,60','Stake: 10',
         'Análise original integral da tip, sem alterar o argumento do autor.'] as $required){
    if(!str_contains($cardText,$required))throw new RuntimeException('Missing approved card text: '.$required);
}
foreach(['WINAMAX','2.000,00 €','3.200,00 €','1.200,00 €','16h15','Sábado'] as $forbidden){
    if(str_contains($cardText,$forbidden))throw new RuntimeException('Forbidden receipt detail in card text: '.$forbidden);
}
if(($cardFixture['bookmaker']??'')!=='WINAMAX'||($cardFixture['analysis']??'')!==$cardView['analysis'] ||
   $cardFixture['stake']!=='6/10' || ($cardView['stake']??'')!=='10'){
    throw new RuntimeException('Card view mutated the original bet or its analysis');
}
if(!str_contains(SmartFormatting::asText($cardFixture,true),'WINAMAX')){
    throw new RuntimeException('Non-card formatting changed unexpectedly');
}
foreach(['1','0'] as $usePure){
    putenv('VIP_CARD_FORCE_PURE='.$usePure);
    $first=VipCardRenderer::render($cardFixture);
    if($first===null)throw new RuntimeException('Approved card did not render');
    $firstSize=getimagesize($first);
    if(!is_array($firstSize)||$firstSize['mime']!=='image/png'||$firstSize[0]!==1080){
        throw new RuntimeException('Approved card dimensions or MIME invalid');
    }
    $noReceipt=$cardFixture;
    foreach(['time','day','bookmaker','stake_amount','potential_return','potential_profit'] as $field){
        $noReceipt[$field]='RECEIPT_DATA_NEVER_ON_CARD';
    }
    $second=VipCardRenderer::render($noReceipt);
    if($second===null||hash_file('sha256',$first)!==hash_file('sha256',$second)){
        throw new RuntimeException('Card renderer included forbidden receipt fields');
    }
    $differentAnalysis=$cardFixture;
    $differentAnalysis['analysis']='Outra análise original independente.';
    $third=VipCardRenderer::render($differentAnalysis);
    if($third===null||hash_file('sha256',$first)===hash_file('sha256',$third)){
        throw new RuntimeException('Card renderer omitted the original analysis');
    }
    foreach([$first,$second,$third] as $tmp)@unlink($tmp);
}
// The new seals and separators must not affect the full original analysis,
// betting details, or the fallback's ability to produce a valid PNG.
foreach(['1','0'] as $usePure){
    putenv('VIP_CARD_FORCE_PURE='.$usePure);
    $sportExample=$cardFixture;
    $sportExample['sport']='Futebol';
    $sportExample['analysis']='A análise do autor deve permanecer inteira, sem resumos ou alteração de frases.';
    $baseline=VipCardRenderer::render($sportExample);
    if($baseline===null)throw new RuntimeException('VIP sport seal baseline failed');
    $size=getimagesize($baseline);
    if(!is_array($size)||$size[0]!==1080||$size['mime']!=='image/png')
        throw new RuntimeException('Invalid VIP seal PNG');
    $sportExample['sport']='Basquete';
    $otherSport=VipCardRenderer::render($sportExample);
    if($otherSport===null||hash_file('sha256',$baseline)===hash_file('sha256',$otherSport))
        throw new RuntimeException('Sport badge did not follow the identified sport');
    $sportExample['sport']='Futebol';
    $sportExample['bookmaker']='ANOTHER_BOOKMAKER';
    $sportExample['time']='00h00';
    $sportExample['potential_profit']='123.456,00 €';
    $withoutReceipt=VipCardRenderer::render($sportExample);
    if($withoutReceipt===null||hash_file('sha256',$baseline)!==hash_file('sha256',$withoutReceipt))
        throw new RuntimeException('VIP seal leaked source-tip time or money');
    foreach([$baseline,$otherSport,$withoutReceipt] as $imagePath)@unlink($imagePath);
}
putenv('VIP_CARD_FORCE_PURE=0');
if(extension_loaded('gd') && function_exists('imagettftext')){
    $fonts=['/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf'];
    if(!array_filter($fonts,'is_file'))throw new RuntimeException('GD font missing in CI');
}
// Regression: MadelineProto converts PHP GD deprecations/warnings into exceptions.
// Exercise the real TrueType renderer with an equally strict handler.
if(extension_loaded('gd') && function_exists('imagettftext')){
    putenv('VIP_CARD_FORCE_PURE=0');
    set_error_handler(static function(int $severity,string $message): never {
        throw new ErrorException($message,0,$severity);
    });
    try {
        foreach(['Futebol','Basquete','Tênis','Vôlei','Hóquei','Beisebol'] as $sport){
            $sportExample=$cardFixture;
            $sportExample['sport']=$sport;
            $sportExample['analysis']='Análise original preservada: '.$sport;
            $strictImage=VipCardRenderer::render($sportExample);
            if($strictImage===null)throw new RuntimeException('GD strict render failed: '.$sport);
            $strictMeta=getimagesize($strictImage);
            if(!is_array($strictMeta)||($strictMeta['mime']??'')!=='image/png')
                throw new RuntimeException('GD strict render returned invalid PNG: '.$sport);
            @unlink($strictImage);
        }
    } finally {
        restore_error_handler();
    }
    echo "SMART_FORMAT_GD_STRICT_HANDLER_TESTS_PASSED\\n";
}
// Production regression: the worker uses PHP 8.5 and MadelineProto converts
// imagedestroy() deprecations to exceptions. A silent GD->bitmap fallback makes
// the card appear pixelated even though the image is delivered successfully.
$gdRendererSource=file_get_contents(__DIR__.'/../app/VipCardRenderer.php');
if(!is_string($gdRendererSource)||preg_match('/\\bimagedestroy\\s*\\(/',$gdRendererSource)){
    throw new RuntimeException('Deprecated imagedestroy will disable TrueType in PHP 8.5');
}
if(extension_loaded('gd')&&function_exists('imagettftext')){
    $typefaceBet=$cardFixture;
    $typefaceBet['sport']='Futebol';
    putenv('VIP_CARD_FORCE_PURE=1');
    $pixelSample=VipCardRenderer::render($typefaceBet);
    putenv('VIP_CARD_FORCE_PURE=0');
    $trueTypeSample=VipCardRenderer::render($typefaceBet);
    if($pixelSample===null||$trueTypeSample===null||
       hash_file('sha256',$pixelSample)===hash_file('sha256',$trueTypeSample)){
        throw new RuntimeException('Premium TrueType renderer unexpectedly fell back to pixel font');
    }
    @unlink($pixelSample);@unlink($trueTypeSample);
    echo "SMART_FORMAT_TRUE_TYPE_NOT_BITMAP_TESTS_PASSED\\n";
}
// Live indicator belongs exclusively to explicitly live betting tips and is
// independently visible from the VIP seal. Sport must be specific when sourced.
$identifiedSport=SmartFormatting::cardView([
    'sport'=>'','match'=>'Athletic Bilbao × Alavés',
    'league'=>'España Primera división','status'=>'AO VIVO'
]);
if(($identifiedSport['sport']??'')!=='Futebol'||($identifiedSport['status']??'')!=='AO VIVO'){
    throw new RuntimeException('League-backed sport or live status lost in card view');
}
// Actual user receipt: the AI returned the generic string "ESPORTE"
// alongside a football-specific Spanish competition.
$genericSport=SmartFormatting::cardView([
    'sport'=>'ESPORTE','match'=>'Athletic Bilbao - Alavés',
    'league'=>'España Primera división','status'=>'en vivo',
    'analysis'=>'Análise original preservada integralmente.'
]);
if(($genericSport['sport']??'')!=='Futebol'||($genericSport['status']??'')!=='AO VIVO')
    throw new RuntimeException('Generic sport or en vivo was not normalized on approved card');
foreach(['esportes','sports','unknown'] as $placeholder){
    $inferred=SmartFormatting::cardView(['sport'=>$placeholder,'league'=>'La Liga']);
    if(($inferred['sport']??'')!=='Futebol')
        throw new RuntimeException('Generic sport placeholder was not replaced: '.$placeholder);
}
foreach(['live','in-play','em andamento','partido en curso','en directo'] as $status){
    $normal=SmartFormatting::cardView(['sport'=>'Futebol','status'=>$status]);
    if(($normal['status']??'')!=='AO VIVO')
        throw new RuntimeException('Explicit live match status not normalized: '.$status);
}
foreach(['En curso','bilhete em aberto','pré-jogo',''] as $notLive){
    $normal=SmartFormatting::cardView(['sport'=>'Futebol','status'=>$notLive]);
    if(($normal['status']??'')==='AO VIVO')
        throw new RuntimeException('Ticket status incorrectly inferred as live');
}
$unknownSport=SmartFormatting::cardView(['sport'=>'','league'=>'Liga desconhecida']);
if(($unknownSport['sport']??'')!=='')throw new RuntimeException('Sport invented from unknown league');
$knownSport=SmartFormatting::cardView(['sport'=>'Basquete','league'=>'La Liga']);
if(($knownSport['sport']??'')!=='Basquete')throw new RuntimeException('Explicit sport incorrectly overwritten');
foreach(['1','0'] as $rendererMode){
    putenv('VIP_CARD_FORCE_PURE='.$rendererMode);
    $base=$cardFixture;
    $base['sport']='Futebol';
    $base['status']='';
    $standard=VipCardRenderer::render($base);
    $base['status']='AO VIVO';
    $live=VipCardRenderer::render($base);
    $base['status']='EN CURSO'; // Can mean ticket open, not that match is underway.
    $openTicket=VipCardRenderer::render($base);
    if($standard===null||$live===null||$openTicket===null)
        throw new RuntimeException('VIP or live badge failed to render');
    if(hash_file('sha256',$standard)===hash_file('sha256',$live))
        throw new RuntimeException('AO VIVO did not generate its own visual badge');
    if(hash_file('sha256',$standard)!==hash_file('sha256',$openTicket))
        throw new RuntimeException('Open ticket incorrectly got AO VIVO badge');
    $base['status']='AO VIVO';
    $base['bookmaker']='OTHER';$base['time']='00h00';$base['stake_amount']='999.99';
    $withoutReceipt=VipCardRenderer::render($base);
    if($withoutReceipt===null||hash_file('sha256',$live)!==hash_file('sha256',$withoutReceipt))
        throw new RuntimeException('Source tip metadata leaked into the live card');
    foreach([$standard,$live,$openTicket,$withoutReceipt] as $generated)@unlink($generated);
}
echo "SMART_FORMAT_LIVE_SPORT_BADGES_TESTS_PASSED\n";

/* Source-grounding regression suite: odds, live status, match identity and
 * generated technical analysis are validated independently from providers. */
$invokePrivate=static function(string $name,array $args=[]){
    $method=new ReflectionMethod(SmartFormatting::class,$name);
    $method->setAccessible(true);
    return $method->invokeArgs(null,$args);
};

foreach([
    ['Odd: 1.50','1.50'],
    ['ODD = 1,85','1,85'],
    ['Corners O8.5 @ 1.91','1.91'],
    ['cuota 2,05','2,05'],
] as [$source,$expectedOdd]){
    $actual=$invokePrivate('sourceOdd',[$source]);
    if($actual!==$expectedOdd)throw new RuntimeException('Source odd extraction mismatch: '.$source);
}
if($invokePrivate('sourceOdd',['Odd 1.80 e odd 1.90'])!==null)
    throw new RuntimeException('Ambiguous multiple odds should not be forced');
if(!$invokePrivate('sourceContainsOddValue',['Mercado over 8.5. Odd 1,85.','1.85']) ||
   $invokePrivate('sourceContainsOddValue',['Mercado over 8.5. Horário 19:45.','1.85']))
    throw new RuntimeException('Odd source grounding confused line/time with quote');

foreach([
    ['AO VIVO - Arsenal x Chelsea',true],
    ['In-play Arsenal x Chelsea',true],
    ['pré-jogo Arsenal x Chelsea',false],
    ['não está ao vivo',false],
    ["Placar mostrado no post: 1-0, 67'",false],
] as [$source,$expected]){
    if($invokePrivate('explicitLiveInText',[$source])!==$expected)
        throw new RuntimeException('Live evidence classification regression: '.$source);
}
if(!$invokePrivate('explicitPreMatchInText',['TIP PRÉ-JOGO - Arsenal x Chelsea']))
    throw new RuntimeException('Pre-match evidence not detected');

if(!$invokePrivate('matchGrounded',['Manchester United x Fulham','Tip: Manchester United x Fulham. Mercado BTTS']) ||
   $invokePrivate('matchGrounded',['Manchester United x Chelsea','Tip: Manchester United x Fulham. Mercado BTTS']))
    throw new RuntimeException('Match grounding did not distinguish opponent identity');

$guard=$invokePrivate('applySourceGuards',[
    ['match'=>'Manchester United x Fulham','market'=>'BTTS','selection'=>'Sim','odd'=>'1.55','status'=>'AO VIVO','analysis'=>''],
    "Manchester United x Fulham\nMercado: Ambas marcam - Sim\nOdd: 1.50",
    false
]);
if(!is_array($guard)||($guard['odd']??'')!=='1.50'||($guard['status']??'')!=='')
    throw new RuntimeException('Source guard failed exact odd/live correction');

$guardLive=$invokePrivate('applySourceGuards',[
    ['match'=>'Arsenal x Chelsea','market'=>'Mais de 2,5 gols','selection'=>'Mais de 2,5','odd'=>'1.90','status'=>'','analysis'=>''],
    "AO VIVO - Arsenal x Chelsea\nMercado: Mais de 2,5 gols\nOdd: 1.90",
    false
]);
if(!is_array($guardLive)||($guardLive['status']??'')!=='AO VIVO')
    throw new RuntimeException('Explicit source live status was not preserved');

if($invokePrivate('applySourceGuards',[
    ['match'=>'Manchester United x Chelsea','market'=>'BTTS','selection'=>'Sim','odd'=>'1.50','status'=>'','analysis'=>''],
    "Manchester United x Fulham\nMercado: Ambas marcam - Sim\nOdd: 1.50",
    false
])!==null)throw new RuntimeException('Ungrounded opponent was accepted');

if(!$invokePrivate('multipleDistinctMatchesInText',[
    "Manchester United x Fulham | BTTS @1.50\nArsenal x Chelsea | Over 2.5 @1.80"
]) || $invokePrivate('multipleDistinctMatchesInText',[
    "Manchester United x Fulham\nMercado BTTS\nManchester United x Fulham"
])){
    throw new RuntimeException('Multiple-event detection regression');
}

foreach(['AO VIVO','live','in-play','partida em andamento','en directo'] as $statusValue){
    if(!$invokePrivate('statusLooksLive',[$statusValue]))
        throw new RuntimeException('Live status synonym escaped source guard: '.$statusValue);
}

if($invokePrivate('containsAnalysis',["Manchester United x Fulham\nMercado: BTTS\nOdd: 1.50"]) ||
   !$invokePrivate('containsAnalysis',["Manchester United x Fulham\nAnálise: O mercado exige gols dos dois lados. A seleção depende de cada equipe marcar ao menos uma vez."]))
    throw new RuntimeException('Analysis detection regression');

$technical=$invokePrivate('technicalAnalysis',[
    ['match'=>'Manchester United x Fulham','market'=>'Ambas as equipes marcam','selection'=>'Sim'],
    'pt-BR'
]);
if(!str_contains($technical,'cada equipe precisa marcar pelo menos um gol') ||
   preg_match('/\b(?:últimos jogos|desfalque|probabilidade|forma recente)\b/iu',$technical))
    throw new RuntimeException('Grounded BTTS technical analysis regression');

$generatedText=SmartFormatting::asText([
    'match'=>'Manchester United x Fulham',
    'market'=>'Ambas as equipes marcam',
    'selection'=>'Sim',
    'odd'=>'1.50',
    'stake'=>'10',
    'analysis'=>$technical,
    'analysis_generated'=>true
],true);
if(!str_contains($generatedText,'Análise inteligente:') ||
   str_contains($generatedText,'Análise original:'))
    throw new RuntimeException('Generated analysis provenance label regression');

$sourceAnalysisText=SmartFormatting::asText([
    'match'=>'Manchester United x Fulham',
    'market'=>'Ambas as equipes marcam',
    'selection'=>'Sim',
    'odd'=>'1.50',
    'stake'=>'10',
    'analysis'=>'Comentário esportivo informado pelo autor.',
    'analysis_generated'=>false
],true);
if(!str_contains($sourceAnalysisText,'Análise original:'))
    throw new RuntimeException('Source analysis provenance label regression');

$overAnalysis=$invokePrivate('technicalAnalysis',[
    ['match'=>'Venezia x Lazio','market'=>'Total de escanteios','selection'=>'Mais de 8,5'],
    'pt-BR'
]);
if(!str_contains($overAnalysis,'linha 8,5')||!str_contains($overAnalysis,'acima'))
    throw new RuntimeException('Grounded totals technical analysis regression');

$smartSource=(string)file_get_contents(__DIR__.'/../app/SmartFormatting.php');
foreach([
    "['match','market','selection','odd','status','analysis']",
    'Preserve a odd exatamente como aparece na origem',
    'Retorne um objeto JSON com match, market, selection, odd, status e analysis'
] as $requiredSourceGuard){
    if(!str_contains($smartSource,$requiredSourceGuard))
        throw new RuntimeException('Smart-format source grounding code missing: '.$requiredSourceGuard);
}

echo "SMART_FORMAT_SOURCE_GROUNDING_TESTS_PASSED\n";

// Regression: enabling both intelligent card and legacy translation performs
// one AI formatting+translation pass, not two Gemini translation calls.
$translatedRule=['id'=>16,'translation_enabled'=>1,'translation_target_language'=>'pt-BR'];
$activeCard=['enabled'=>true,'output_mode'=>'card'];
if(!SmartFormatting::cardHandlesTranslation($translatedRule,$activeCard))
    throw new RuntimeException('Card did not take ownership of translation');
foreach(['card','caption','text'] as $mode){
    if(!SmartFormatting::cardHandlesTranslation($translatedRule,['enabled'=>true,'output_mode'=>$mode]))
        throw new RuntimeException('Enabled smart mode did not take ownership of translation: '.$mode);
}
foreach([
    [['enabled'=>false,'output_mode'=>'card'],$translatedRule],
    [$activeCard,['translation_enabled'=>0]]
] as [$setting,$rule]){
    if(SmartFormatting::cardHandlesTranslation($rule,$setting))
        throw new RuntimeException('Legacy translation intercepted for disabled rule');
}
// Mandatory AI errors must remain actionable without revealing raw tip text.
$diagMethod=(new ReflectionClass(SmartFormatting::class))->getMethod('diag');
$diagMethod->invoke(null,'WORKERS_AI_RESPONSE_MISSING_TEXT');
$diagMethod->invoke(null,'SMART_PROVIDER_FAILED_workers_ai');
$diagMethod->invoke(null,'GEMINI_CURL_TIMEOUT');
$diagMethod->invoke(null,'ALL_CONFIGURED_PROVIDERS_FAILED');
$summary=SmartFormatting::failureSummary();
if(!str_contains($summary,'WORKERS_AI_RESPONSE_MISSING_TEXT') ||
   !str_contains($summary,'GEMINI_CURL_TIMEOUT') ||
   str_contains($summary,'ALL_CONFIGURED_PROVIDERS_FAILED') ||
   str_contains($summary,'SMART_PROVIDER_FAILED_'))
    throw new RuntimeException('Per-tip failure summary missing real provider reason');
SmartFormatting::prepare('',[],null,'card');
if(SmartFormatting::failureSummary()!=='SOURCE_EMPTY')
    throw new RuntimeException('Stale failure code leaked between messages');
$runtimeSource=file_get_contents(__DIR__.'/../runtime-smart-format.php');
if(!is_string($runtimeSource)
   ||!str_contains($runtimeSource,'SINGLE_PASS_CARD_TRANSLATION')
   ||!str_contains($runtimeSource,'SMART_FORMAT_REQUIRED_UNAVAILABLE')
   ||!str_contains($runtimeSource,'TMR_SMART_FORMAT_REQUIRED_FAILED')
   ||!str_contains($runtimeSource,'SmartFormatting::failureSummary()')
   ||!str_contains($runtimeSource,"if(!empty(\$setting['enabled']))")
   ||str_contains($runtimeSource,'translationFallback=Transform::translateDetailed')
   ||str_contains($runtimeSource,'TMR_SMART_CARD_TRANSLATION_FALLBACK'))
    throw new RuntimeException('Mandatory opt-in formatting or single-pass translation missing');
$failGuard=strpos($runtimeSource,'SMART_FORMAT_REQUIRED_UNAVAILABLE');
$rawDelivery=strpos($runtimeSource,"if(\$deliveryMedia===null){", $failGuard?:0);
if($failGuard===false||$rawDelivery===false||$failGuard>$rawDelivery)
    throw new RuntimeException('Unformatted legacy send reachable before mandatory AI guard');
$transportSource=file_get_contents(__DIR__.'/../scripts/smart-gemini-isolated.php');
if(!is_string($transportSource)||!str_contains($transportSource,'$curlErr===28')||
   !str_contains($transportSource,'[500,502,503,504]')||
   str_contains($transportSource,'[429,500,502,503,504]'))
    throw new RuntimeException('Transient AI timeout/network retry is not installed');
if(!\App\SmartFormatting::workerVisualRescueEligible('HTTP_400') ||
   !\App\SmartFormatting::workerVisualRescueEligible('HTTP_400_CF_3030_MISSING_INPUT') ||
   \App\SmartFormatting::workerVisualRescueEligible('HTTP_400_CF_3030_POLICY') ||
   \App\SmartFormatting::workerVisualRescueEligible('HTTP_400_CF_3030_UNCLASSIFIED') ||
   \App\SmartFormatting::workerVisualRescueEligible('IMAGE_INPUT_INVALID_5004') ||
   \App\SmartFormatting::workerVisualRescueEligible('HTTP_429') ||
   \App\SmartFormatting::workerVisualRescueEligible('MODEL_NOT_FOUND_5007'))
    throw new RuntimeException('Vision model-specific bad-request rescue guard regression');
echo "SMART_FORMAT_REQUIRED_NO_RAW_FALLBACK_TESTS_PASSED\n";
echo "SMART_FORMAT_SINGLE_PASS_TRANSLATION_TESTS_PASSED\n";
echo "SMART_FORMAT_VIP_SEAL_TESTS_PASSED\n";
echo "SMART_FORMAT_APPROVED_DAY_CARD_TESTS_PASSED\n";
echo "SMART_FORMAT_LIVE_SLIP_TESTS_PASSED\n";
echo "SMART_FORMAT_TESTS_PASSED\n";
