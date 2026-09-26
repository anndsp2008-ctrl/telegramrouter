<?php declare(strict_types=1);

require_once __DIR__.'/runtime-workers-ai.php';
require_once __DIR__.'/runtime-openai.php';

/*
 * Integrations UI v10.
 * Premium compact workspace, one stylesheet and one behavior file.
 */

$path = __DIR__.'/index.php';
if (!is_file($path)) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_INDEX_MISSING\n");
    exit(1);
}

$source = (string)file_get_contents($path);

// Show the saved diagnostic in the EXISTING status span, not in a new row.
// The compact integration layout intentionally hides the legacy <em> error.
$showFailureReason=static function(string $body,string $testVar,string $provider): string {
    $startTag='<span><?=
// Remove legacy dedicated layout and any previous integrations behavior bundle.
$source = preg_replace(
    '~<link\b[^>]*href=["\']/assets/integrations-layout\.css[^"\']*["\'][^>]*>~i',
    '',
    $source
) ?? $source;

$source = preg_replace(
    '~<!-- integrations_ui_v(?:2|5|6|9|10) -->\s*<section class="(?:integrations-hero-v2|integrations-overview-v5|integrations-summary-v6|integrations-overview-v9|integrations-overview-v10)">.*?</section>\s*~s',
    '',
    $source
) ?? $source;

$marker = '<section class="saas-card integrations-connection">';
if (!str_contains($source, $marker)) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_MARKER_MISSING\n");
    exit(1);
}

$overview = <<<'HTML'
<!-- integrations_ui_v10 -->
<section class="integrations-overview-v10">
  <div class="integrations-overview-v10-copy">
    <span>CENTRAL DE INTEGRAÇÕES</span>
    <strong>Serviços externos do Telegram Router</strong>
    <small>Configure credenciais, valide conexões e acompanhe o estado de cada provedor sem poluição visual.</small>
  </div>
  <div class="integrations-overview-v10-status">
    <span class="<?=((!empty($azureKey)?1:0)+(!empty($geminiKey)?1:0)+(!empty($googleCloudKey)?1:0)+((!empty($workersAIKey)&&!empty($workersAIAccount))?1:0))>0?'is-good':''?>">Chaves <?=((!empty($azureKey)?1:0)+(!empty($geminiKey)?1:0)+(!empty($googleCloudKey)?1:0)+((!empty($workersAIKey)&&!empty($workersAIAccount))?1:0))?>/4</span>
    <span class="<?=$connectedPhone!==''?'is-good':''?>">Telegram <?=$connectedPhone!==''?'online':'offline'?></span>
  </div>
</section>
HTML;

$source = str_replace($marker, $overview."\n".$marker, $source, $insertCount);
if ($insertCount !== 1) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_INSERT_FAILED\n");
    exit(1);
}

// Normalize translation heading while keeping all provider PHP/forms/tests intact.
$source = preg_replace(
    '~<div class="integrations-section-heading">.*?</div><div class="translation-provider-grid">~s',
    '<div class="integrations-section-heading"><div><span class="saas-kicker">PROVEDORES DE TRADUÇÃO</span><h2>Credenciais e conexão</h2><p>Clique no card para expandir. Cada provedor mantém sua própria chave, teste de conexão, data/hora, latência e diagnóstico.</p></div></div><div class="translation-provider-grid">',
    $source,
    1,
    $headingCount
) ?? $source;

if ($headingCount !== 1) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_HEADING_FAILED\n");
    exit(1);
}

$source = preg_replace(
    "~<script\b[^>]*src=[\"'][^\"']*integrations-(?:v2|ui-v4|ui-v5|ui-v6|v9|v10)\.js[^\"']*[\"'][^>]*></script>~i",
    '',
    $source
) ?? $source;

$source = str_replace(
    '</body>',
    '<script src="/assets/brand/integrations-v10.js?v=6" defer></script></body>',
    $source
);

if (@file_put_contents($path, $source) === false) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_WRITE_FAILED\n");
    exit(1);
}

$verify = (string)file_get_contents($path);
if (
    !str_contains($verify, 'integrations_ui_v10') ||
    !str_contains($verify, '/assets/brand/integrations-v10.js?v=6') ||
    str_contains($verify, '/assets/integrations-layout.css')
) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_VERIFY_FAILED\n");
    exit(1);
}

echo "INTEGRATIONS_UI_V10_APPLIED\n";
.$testVar.'?';
    $start=strpos($body,$startTag);
    if($start===false)throw new RuntimeException('INTEGRATIONS_TEST_STATUS_MISSING_'.$provider);
    if(strpos($body,$startTag,$start+1)!==false)
        throw new RuntimeException('INTEGRATIONS_TEST_STATUS_DUPLICATED_'.$provider);
    $end=strpos($body,'</span>',$start);
    if($end===false)throw new RuntimeException('INTEGRATIONS_TEST_STATUS_END_MISSING_'.$provider);
    $old=substr($body,$start,$end+7-$start);
    if(str_contains($old,"'last_test_error'"))return $body; // Idempotent; OpenAI already uses this display.
    if(!str_contains($old,"'Falha'"))
        throw new RuntimeException('INTEGRATIONS_TEST_STATUS_UNEXPECTED_'.$provider);
    $new=<<<'PHP'
<span><?=$__TEST__?((int)$__TEST__['last_test_ok']?'Conexão válida':'Falha'.(!empty($__TEST__['last_test_error'])?' — '.sh((string)$__TEST__['last_test_error']):(!empty($__TEST__['last_test_http_code'])?' — HTTP '.(int)$__TEST__['last_test_http_code']:''))):'Ainda não testado'?></span>
PHP;
    return substr_replace($body,str_replace('__TEST__',$testVar,$new),$start,strlen($old));
};

$source=$showFailureReason($source,'geminiTest','GEMINI');
foreach([
    'googleCloudTest'=>__DIR__.'/app/google-cloud-card.php',
    'workersAITest'=>__DIR__.'/app/workers-ai-card.php',
] as $testVar=>$cardPath){
    if(!is_file($cardPath))throw new RuntimeException('INTEGRATIONS_TEST_CARD_MISSING_'.$testVar);
    $original=(string)file_get_contents($cardPath);
    $updated=$showFailureReason($original,$testVar,strtoupper($testVar));
    if($updated!==$original && file_put_contents($cardPath,$updated,LOCK_EX)===false)
        throw new RuntimeException('INTEGRATIONS_TEST_CARD_WRITE_FAILED_'.$testVar);
}

// Provider test feedback is handled inside each provider card by integrations-v10.js.
// No broad alert/aria-live suppression is used here, because it can hide large UI containers.

// Remove legacy dedicated layout and any previous integrations behavior bundle.
$source = preg_replace(
    '~<link\b[^>]*href=["\']/assets/integrations-layout\.css[^"\']*["\'][^>]*>~i',
    '',
    $source
) ?? $source;

$source = preg_replace(
    '~<!-- integrations_ui_v(?:2|5|6|9|10) -->\s*<section class="(?:integrations-hero-v2|integrations-overview-v5|integrations-summary-v6|integrations-overview-v9|integrations-overview-v10)">.*?</section>\s*~s',
    '',
    $source
) ?? $source;

$marker = '<section class="saas-card integrations-connection">';
if (!str_contains($source, $marker)) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_MARKER_MISSING\n");
    exit(1);
}

$overview = <<<'HTML'
<!-- integrations_ui_v10 -->
<section class="integrations-overview-v10">
  <div class="integrations-overview-v10-copy">
    <span>CENTRAL DE INTEGRAÇÕES</span>
    <strong>Serviços externos do Telegram Router</strong>
    <small>Configure credenciais, valide conexões e acompanhe o estado de cada provedor sem poluição visual.</small>
  </div>
  <div class="integrations-overview-v10-status">
    <span class="<?=((!empty($azureKey)?1:0)+(!empty($geminiKey)?1:0)+(!empty($googleCloudKey)?1:0)+((!empty($workersAIKey)&&!empty($workersAIAccount))?1:0))>0?'is-good':''?>">Chaves <?=((!empty($azureKey)?1:0)+(!empty($geminiKey)?1:0)+(!empty($googleCloudKey)?1:0)+((!empty($workersAIKey)&&!empty($workersAIAccount))?1:0))?>/4</span>
    <span class="<?=$connectedPhone!==''?'is-good':''?>">Telegram <?=$connectedPhone!==''?'online':'offline'?></span>
  </div>
</section>
HTML;

$source = str_replace($marker, $overview."\n".$marker, $source, $insertCount);
if ($insertCount !== 1) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_INSERT_FAILED\n");
    exit(1);
}

// Normalize translation heading while keeping all provider PHP/forms/tests intact.
$source = preg_replace(
    '~<div class="integrations-section-heading">.*?</div><div class="translation-provider-grid">~s',
    '<div class="integrations-section-heading"><div><span class="saas-kicker">PROVEDORES DE TRADUÇÃO</span><h2>Credenciais e conexão</h2><p>Clique no card para expandir. Cada provedor mantém sua própria chave, teste de conexão, data/hora, latência e diagnóstico.</p></div></div><div class="translation-provider-grid">',
    $source,
    1,
    $headingCount
) ?? $source;

if ($headingCount !== 1) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_HEADING_FAILED\n");
    exit(1);
}

$source = preg_replace(
    "~<script\b[^>]*src=[\"'][^\"']*integrations-(?:v2|ui-v4|ui-v5|ui-v6|v9|v10)\.js[^\"']*[\"'][^>]*></script>~i",
    '',
    $source
) ?? $source;

$source = str_replace(
    '</body>',
    '<script src="/assets/brand/integrations-v10.js?v=6" defer></script></body>',
    $source
);

if (@file_put_contents($path, $source) === false) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_WRITE_FAILED\n");
    exit(1);
}

$verify = (string)file_get_contents($path);
if (
    !str_contains($verify, 'integrations_ui_v10') ||
    !str_contains($verify, '/assets/brand/integrations-v10.js?v=6') ||
    str_contains($verify, '/assets/integrations-layout.css')
) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_VERIFY_FAILED\n");
    exit(1);
}

echo "INTEGRATIONS_UI_V10_APPLIED\n";
