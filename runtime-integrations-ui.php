<?php declare(strict_types=1);

/*
 * Integrations UI v5.
 * Stable server-rendered presentation. The browser only toggles state classes;
 * it never rebuilds or moves provider markup after first paint.
 */

$path = __DIR__.'/index.php';
if (!is_file($path)) {
    fwrite(STDERR, "INTEGRATIONS_UI_V5_INDEX_MISSING\n");
    exit(1);
}
$source = (string)file_get_contents($path);

if (!str_contains($source, 'integrations_ui_v5')) {
    $marker = '<section class="saas-card integrations-connection">';
    if (!str_contains($source, $marker)) {
        fwrite(STDERR, "INTEGRATIONS_UI_V5_MARKER_MISSING\n");
        exit(1);
    }

    $overview = <<<'HTML'
<!-- integrations_ui_v5 -->
<section class="integrations-overview-v5">
  <div class="copy">
    <span class="saas-kicker">CENTRAL DE INTEGRAÇÕES</span>
    <h2>Conectividade do Telegram Router</h2>
    <p>Gerencie os serviços externos usados pelo roteador, valide credenciais e abra somente o provedor que deseja configurar.</p>
    <div class="integrations-overview-actions">
      <a class="saas-secondary" href="/connect.php">Gerenciar Telegram</a>
      <a class="saas-secondary" href="/?page=rules">Configurar regras</a>
    </div>
  </div>
  <div class="integrations-overview-stats">
    <div>
      <span>Provedores</span>
      <strong><?=((!empty($azureKey)?1:0)+(!empty($geminiKey)?1:0)+(!empty($googleCloudKey)?1:0))?> / 3</strong>
      <small>com credencial cadastrada</small>
    </div>
    <div>
      <span>Testes válidos</span>
      <strong><?=((!empty($azureTest['last_test_ok'])?1:0)+(!empty($geminiTest['last_test_ok'])?1:0)+(!empty($googleCloudTest['last_test_ok'])?1:0))?> / 3</strong>
      <small>últimos testes aprovados</small>
    </div>
    <div>
      <span>Telegram</span>
      <strong><?=$connectedPhone!==''?'Conectado':'Desconectado'?></strong>
      <small>sessão de encaminhamento</small>
    </div>
  </div>
</section>
HTML;

    $source = str_replace($marker, $overview."\n".$marker, $source, $count);
    if ($count !== 1) {
        fwrite(STDERR, "INTEGRATIONS_UI_V5_INSERT_FAILED\n");
        exit(1);
    }

    $source = str_replace(
        '<div class="integrations-section-heading"><h2>Serviços de tradução</h2><p>Cadastre a chave de cada serviço, teste a conexão e consulte os resultados abaixo.</p></div>',
        '<div class="integrations-section-heading"><div><span class="saas-kicker">PROVEDORES</span><h2>Credenciais e conectividade</h2><p>Clique em qualquer bloco para expandir. Apenas um provedor permanece aberto por vez.</p></div></div>',
        $source
    );

    if (@file_put_contents($path, $source) === false) {
        fwrite(STDERR, "INTEGRATIONS_UI_V5_WRITE_FAILED\n");
        exit(1);
    }
}

// Keep exactly one current integrations behavior bundle.
$source = (string)file_get_contents($path);
$source = preg_replace(
    "~<script\\b[^>]*src=[\"'][^\"']*integrations-(?:v2|ui-v4|ui-v5)\\.js[^\"']*[\"'][^>]*></script>~i",
    '',
    $source
) ?? $source;
$source = str_replace(
    '</body>',
    '<script src="/assets/brand/integrations-ui-v5.js?v=1" defer></script></body>',
    $source
);
if (@file_put_contents($path, $source) === false) {
    fwrite(STDERR, "INTEGRATIONS_UI_V5_SCRIPT_WRITE_FAILED\n");
    exit(1);
}

$verify = (string)file_get_contents($path);
if (!str_contains($verify, 'integrations_ui_v5') || !str_contains($verify, '/assets/brand/integrations-ui-v5.js?v=1')) {
    fwrite(STDERR, "INTEGRATIONS_UI_V5_VERIFY_FAILED\n");
    exit(1);
}

echo "INTEGRATIONS_UI_V5_APPLIED\n";
