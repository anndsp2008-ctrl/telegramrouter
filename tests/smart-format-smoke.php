<?php declare(strict_types=1);
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
$image=VipCardRenderer::render($bet);
if($image===null)throw new RuntimeException('VIP rendering unavailable even with GD and DejaVu fonts');
$size=getimagesize($image);
if(!is_array($size)||$size['mime']!=='image/png'||$size[0]!==1080)throw new RuntimeException('Invalid VIP PNG');
@unlink($image);
echo "SMART_FORMAT_TESTS_PASSED\n";
