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
// bookmaker, stake amounts, returns, profits, status and source-tip time.
// Non-card text/legend mode continues to expose the original extracted fields.
$cardFixture=$liveBet;
$cardFixture['analysis']='Análise original integral da tip, sem alterar o argumento do autor.';
$cardFixture['bookmaker']='WINAMAX';
$cardFixture['day']='Sábado';
$cardView=SmartFormatting::cardView($cardFixture);
$cardText=SmartFormatting::asText($cardView,true);
foreach(['Athletic Bilbao × Alavés','Vitória do Athletic Bilbao','1,60','6/10',
         'Análise original integral da tip, sem alterar o argumento do autor.'] as $required){
    if(!str_contains($cardText,$required))throw new RuntimeException('Missing approved card text: '.$required);
}
foreach(['WINAMAX','2.000,00 €','3.200,00 €','1.200,00 €','16h15','Sábado','AO VIVO'] as $forbidden){
    if(str_contains($cardText,$forbidden))throw new RuntimeException('Forbidden receipt detail in card text: '.$forbidden);
}
if(($cardFixture['bookmaker']??'')!=='WINAMAX'||($cardFixture['analysis']??'')!==$cardView['analysis']){
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
    foreach(['status','time','day','bookmaker','stake_amount','potential_return','potential_profit'] as $field){
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
echo "SMART_FORMAT_VIP_SEAL_TESTS_PASSED\n";
echo "SMART_FORMAT_APPROVED_DAY_CARD_TESTS_PASSED\n";
echo "SMART_FORMAT_LIVE_SLIP_TESTS_PASSED\n";
echo "SMART_FORMAT_TESTS_PASSED\n";
