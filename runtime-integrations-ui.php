<?php declare(strict_types=1);

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
    <span class="<?=((!empty($azureKey)?1:0)+(!empty($geminiKey)?1:0)+(!empty($googleCloudKey)?1:0))>0?'is-good':''?>">Chaves <?=((!empty($azureKey)?1:0)+(!empty($geminiKey)?1:0)+(!empty($googleCloudKey)?1:0))?>/3</span>
    <span class="<?=((!empty($azureTest['last_test_ok'])?1:0)+(!empty($geminiTest['last_test_ok'])?1:0)+(!empty($googleCloudTest['last_test_ok'])?1:0))>0?'is-good':''?>">Testes <?=((!empty($azureTest['last_test_ok'])?1:0)+(!empty($geminiTest['last_test_ok'])?1:0)+(!empty($googleCloudTest['last_test_ok'])?1:0))?>/3</span>
    <span class="<?=$connectedPhone!==''?'is-good':''?>">Telegram <?=$connectedPhone!==''?'online':'offline'?></span>
  </div>
</section>
HTML;

$source = str_replace($marker, $overview."\n".$marker, $insertCount);
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
    '<script src="/assets/brand/integrations-v10.js?v=1" defer></script></body>',
    $source
);

if (@file_put_contents($path, $source) === false) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_WRITE_FAILED\n");
    exit(1);
}

$verify = (string)file_get_contents($path);
if (
    !str_contains($verify, 'integrations_ui_v10') ||
    !str_contains($verify, '/assets/brand/integrations-v10.js?v=1') ||
    str_contains($verify, '/assets/integrations-layout.css')
) {
    fwrite(STDERR, "INTEGRATIONS_UI_V10_VERIFY_FAILED\n");
    exit(1);
}

echo "INTEGRATIONS_UI_V10_APPLIED\n";
