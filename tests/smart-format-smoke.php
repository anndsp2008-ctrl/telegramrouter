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
echo "SMART_FORMAT_LIVE_SLIP_TESTS_PASSED\n";
echo "SMART_FORMAT_TESTS_PASSED\n";
