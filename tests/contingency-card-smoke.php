<?php declare(strict_types=1);

require_once __DIR__.'/../app/SmartFormatting.php';
require_once __DIR__.'/../app/PurePngVipCardRenderer.php';
require_once __DIR__.'/../app/VipCardRenderer.php';
require_once __DIR__.'/../app/ContingencyCardRenderer.php';

use App\ContingencyCardRenderer;

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
    'ContingencyCardRenderer::render',
    'ContingencyCardRenderer::caption',
    "deliveryMethod='ai_vip_card_contingency",
    'SMART_CARD_CONTINGENCY_UNAVAILABLE',
    '(\$setting[\'output_mode\']??\'\')===\'card\''
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

$labels=(string)file_get_contents(__DIR__.'/../runtime-ui-labels.php');
if(!str_contains($labels,'ai_vip_card_contingency')||
   !str_contains($labels,'Card VIP de contingência')){
    throw new RuntimeException('Contingency activity label missing');
}

echo "SMART_CARD_CONTINGENCY_TESTS_PASSED\n";
