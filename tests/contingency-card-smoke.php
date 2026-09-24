<?php declare(strict_types=1);

require_once __DIR__.'/../app/SmartFormatting.php';
require_once __DIR__.'/../app/PurePngVipCardRenderer.php';
require_once __DIR__.'/../app/VipCardRenderer.php';
require_once __DIR__.'/../app/ContingencyCardRenderer.php';

use App\ContingencyCardRenderer;

// Regression: image shows one Barcelona selection; source caption lists four
// unrelated games. None of those games may reach the contingency caption or PNG.
$receiptFixture=tempnam(sys_get_temp_dir(),'tmr-receipt-grounding-');
if($receiptFixture===false)throw new RuntimeException('Receipt fixture could not be created');
if(extension_loaded('gd')){
    $fixtureImage=imagecreatetruecolor(320,140);
    if(!$fixtureImage||!imagepng($fixtureImage,$receiptFixture)){
        @unlink($receiptFixture);
        throw new RuntimeException('Receipt PNG fixture could not be created');
    }
    unset($fixtureImage);
} else {
    file_put_contents($receiptFixture,base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+1K9sAAAAASUVORK5CYII='
    ));
}
$inventedList="1. Barcelona mais de 3,5 gols @1.50\n".
    "2. Real Madrid mais de 3,5 gols @1.50\n".
    "3. Bayern mais de 3,5 gols @1.50\n".
    "4. Manchester City mais de 3,5 gols @1.50";
$grounded=ContingencyCardRenderer::groundedText($inventedList,$receiptFixture);
if($grounded===$inventedList||
   str_contains($grounded,'Real Madrid')||
   str_contains($grounded,'Bayern')||
   str_contains($grounded,'Manchester City')||
   !str_contains($grounded,'Stake: 10')){
    throw new RuntimeException('Unverified source selections leaked into photo contingency');
}
if(ContingencyCardRenderer::groundedText($inventedList,null)!==$inventedList){
    throw new RuntimeException('Text-only contingency was changed');
}
$trustedAnalysis='El partido será táctico porque ambos equipos defienden con bloques compactos y conceden pocas ocasiones claras.';
if(ContingencyCardRenderer::groundedText($trustedAnalysis,$receiptFixture,true)!==$trustedAnalysis){
    throw new RuntimeException('Verified source analysis was replaced by generic receipt copy');
}
$trustedAnalysisImage=ContingencyCardRenderer::render($trustedAnalysis,$receiptFixture,true);
if($trustedAnalysisImage===null||!is_file($trustedAnalysisImage)){
    throw new RuntimeException('Trusted source analysis did not render beside receipt');
}
@unlink($trustedAnalysisImage);
$photoWithList=ContingencyCardRenderer::render($inventedList,$receiptFixture);
$photoWithSafeText=ContingencyCardRenderer::render($grounded,$receiptFixture);
if($photoWithList===null||$photoWithSafeText===null||
   hash_file('sha256',$photoWithList)!==hash_file('sha256',$photoWithSafeText)){
    throw new RuntimeException('Visual receipt contingency rendered unverified selections');
}
@unlink($photoWithList);@unlink($photoWithSafeText);@unlink($receiptFixture);
echo "RECEIPT_ONLY_CONTINGENCY_TESTS_PASSED\\n";

$emojiVisual=ContingencyCardRenderer::render("⚽ Menos de 5,5 gols 📊 análise sólida",null);
$plainVisual=ContingencyCardRenderer::render("Menos de 5,5 gols análise sólida",null);
if($emojiVisual===null||$plainVisual===null||
   hash_file('sha256',$emojiVisual)!==hash_file('sha256',$plainVisual)){
    throw new RuntimeException('Contingency DETAILS panel still renders emoji');
}
@unlink($emojiVisual);@unlink($plainVisual);

$translated='Vitória do time da casa. A odd informada no conteúdo original foi preservada somente quando explicitamente disponível.';
$caption=ContingencyCardRenderer::caption($translated);
if(!str_contains($caption,'Detalhes da aposta')||!str_contains($caption,$translated)){
    throw new RuntimeException('Contingency caption did not preserve translated text');
}

$image=ContingencyCardRenderer::render($translated,null);
if($image===null||!is_file($image)){
    throw new RuntimeException('Contingency card did not render');
}
$meta=getimagesize($image);
if(!is_array($meta)||($meta['mime']??'')!=='image/png'||($meta[0]??0)!==1080){
    throw new RuntimeException('Contingency card PNG is invalid');
}
@unlink($image);

$runtime=(string)file_get_contents(__DIR__.'/../runtime-smart-format.php');
foreach([
    "'context'=>'smart_card_contingency'",
    "'smart_card_contingency_source_analysis'",
    'SmartFormatting::extractSourceAnalysis($text)',
    '$trustedAnalysis=$receiptOnly && $sourceAnalysis!==\'\'',
    'source_analysis_preserved',
    'ContingencyCardRenderer::render',
    'ContingencyCardRenderer::caption',
    'ai_vip_card_contingency',
    'SMART_CARD_CONTINGENCY_UNAVAILABLE',
    '($setting[\'output_mode\']??\'\')===\'card\''
] as $anchor){
    if(!str_contains($runtime,$anchor)){
        throw new RuntimeException('Mandatory contingency runtime anchor missing: '.$anchor);
    }
}

if(!str_contains($runtime,'$contingencyTranslated=!empty($translationFallback[\'translated\'])')){
    throw new RuntimeException('Contingency translation status is not derived from translation result');
}
if(!str_contains($runtime,"throw new \\RuntimeException('SMART_CARD_CONTINGENCY_UNAVAILABLE')")){
    throw new RuntimeException('Mandatory card can still fall through to raw original delivery');
}

$runtime=(string)file_get_contents(__DIR__.'/../runtime-smart-format.php');
foreach([
    'TMR_SMART_CARD_STAGE_TIMING',
    'SmartFormatting::timingCompact(',
    '$telegramStarted=microtime(true)',
    '$contingencyTranslationMs',
    '$contingencyRenderMs',
    'TMR_SMART_CARD_CONTINGENCY_RULE_CLEAN',
    'Transform::clean($contingencyText,$rule,[])',
    'CardLayoutEmojis::clean(',
    'SmartFormatting::normalizePublishedStakeText('
] as $timingAnchor){
    if(!str_contains($runtime,$timingAnchor)){
        throw new RuntimeException('Card stage timing anchor missing: '.$timingAnchor);
    }
}
$smart=(string)file_get_contents(__DIR__.'/../app/SmartFormatting.php');
foreach([
    'public static function timingCompact(',
    "'render_ms'=>self::\$renderMs",
    'self::$aiFinishedAt=microtime(true)',
    '$renderStarted=microtime(true)'
] as $timingAnchor){
    if(!str_contains($smart,$timingAnchor)){
        throw new RuntimeException('Smart timing source anchor missing: '.$timingAnchor);
    }
}
$labels=(string)file_get_contents(__DIR__.'/../runtime-ui-labels.php');
if(!str_contains($labels,'ai_vip_card_contingency')||
   !str_contains($labels,'Card VIP de contingência')||
   !str_contains($labels,'Processamento + envio:')||
   str_contains($labels,'Envio ao Telegram:')){
    throw new RuntimeException('Contingency activity label missing');
}

echo "SMART_CARD_CONTINGENCY_TESTS_PASSED\n";
