<?php declare(strict_types=1);

/*
 * Integrations UI v6.
 * Clean settings workspace with stable full-card accordion.
 */

$path = __DIR__.'/index.php';
if (!is_file($path)) {
    fwrite(STDERR, "INTEGRATIONS_UI_V6_INDEX_MISSING\n");
    exit(1);
}
$source = (string)file_get_contents($path);

if (!str_contains($source, 'integrations_ui_v6')) {
    $marker = '<section class="saas-card integrations-connection">';
    if (!str_contains($source, $marker)) {
        fwrite(STDERR, "INTEGRATIONS_UI_V6_MARKER_MISSING\n");
        exit(1);
    }

    $summary = <<<'HTML'
<!-- integrations_ui_v6 -->
<section class="integrations-summary-v6">
  <div class="integrations-summary-copy">
    <span>INTEGRAÇÕES</span>
    <strong>Serviços conectados ao Telegram Router</strong>
    <small>Abra apenas o provedor que deseja configurar ou testar.</small>
  </div>
  <div class="integrations-summary-metrics">
    <div class="integrations-summary-metric">
      <span>Credenciais</span>
      <strong><?=((!empty($azureKey)?1:0)+(!empty($geminiKey)?1:0)+(!empty($googleCloudKey)?1:0))?> / 3</strong>
    </div>
    <div class="integrations-summary-metric">
      <span>Testes válidos</span>
      <strong><?=((!empty($azureTest['last_test_ok'])?1:0)+(!empty($geminiTest['last_test_ok'])?1:0)+(!empty($googleCloudTest['last_test_ok'])?1:0))?> / 3</strong>
    </div>
    <div class="integrations-summary-metric">
      <span>Telegram</span>
      <strong><?=$connectedPhone!==''?'Online':'Offline'?></strong>
    </div>
  </div>
</section>
HTML;

    $source = str_replace($marker, $summary."\n".$marker, $source, $count);
    if ($count !== 1) {
        fwrite(STDERR, "INTEGRATIONS_UI_V6_INSERT_FAILED\n");
        exit(1);
    }

    $source = str_replace(
        '<div class="integrations-section-heading"><h2>Serviços de tradução</h2><p>Cadastre a chave de cada serviço, teste a conexão e consulte os resultados abaixo.</p></div>',
        '<div class="integrations-section-heading"><div><span class="saas-kicker">PROVEDORES DE TRADUÇÃO</span><h2>Credenciais e conexão</h2><p>Clique em um provedor para expandir ou recolher suas configurações.</p></div></div>',
        $source
    );

    if (@file_put_contents($path, $source) === false) {
        fwrite(STDERR, "INTEGRATIONS_UI_V6_WRITE_FAILED\n");
        exit(1);
    }
}

// Keep exactly one integrations behavior bundle.
$source = (string)file_get_contents($path);
$source = preg_replace(
    "~<script\\b[^>]*src=[\"'][^\"']*integrations-(?:v2|ui-v4|ui-v5|ui-v6)\\.js[^\"']*[\"'][^>]*></script>~i",
    '',
    $source
) ?? $source;
$source = str_replace(
    '</body>',
    '<script src="/assets/brand/integrations-ui-v6.js?v=1" defer></script></body>',
    $source
);
if (@file_put_contents($path, $source) === false) {
    fwrite(STDERR, "INTEGRATIONS_UI_V6_SCRIPT_WRITE_FAILED\n");
    exit(1);
}

$verify = (string)file_get_contents($path);
if (!str_contains($verify, 'integrations_ui_v6') || !str_contains($verify, '/assets/brand/integrations-ui-v6.js?v=1')) {
    fwrite(STDERR, "INTEGRATIONS_UI_V6_VERIFY_FAILED\n");
    exit(1);
}

echo "INTEGRATIONS_UI_V6_APPLIED\n";
