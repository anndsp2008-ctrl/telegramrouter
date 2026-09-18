<?php declare(strict_types=1);

/*
 * Integrations UI v2.
 * Runs after the functional integrations patch and changes presentation only.
 */

$path = __DIR__.'/index.php';
if (!is_file($path)) {
    fwrite(STDERR, "INTEGRATIONS_UI_V2_INDEX_MISSING\n");
    exit(1);
}
$source = (string)file_get_contents($path);

if (!str_contains($source, 'integrations_ui_v2')) {
    $marker = '<section class="saas-card integrations-connection">';
    if (!str_contains($source, $marker)) {
        fwrite(STDERR, "INTEGRATIONS_UI_V2_MARKER_MISSING\n");
        exit(1);
    }

    $hero = <<<'HTML'
<!-- integrations_ui_v2 -->
<section class="integrations-hero-v2">
  <div class="integrations-hero-copy">
    <span class="saas-kicker">CENTRAL DE INTEGRAÇÕES</span>
    <h2>Serviços conectados ao roteador</h2>
    <p>Gerencie credenciais, valide a conexão de cada provedor e acompanhe rapidamente o estado dos serviços usados nas automações.</p>
    <div class="integrations-hero-actions">
      <a class="saas-secondary" href="/connect.php">Gerenciar Telegram</a>
      <a class="saas-secondary" href="/?page=rules">Configurar regras</a>
    </div>
  </div>
  <div class="integrations-health-v2">
    <div>
      <span>Provedores cadastrados</span>
      <strong><?=((!empty($azureKey)?1:0)+(!empty($geminiKey)?1:0)+(!empty($googleCloudKey)?1:0))?> / 3</strong>
      <small>credenciais disponíveis</small>
    </div>
    <div>
      <span>Testes válidos</span>
      <strong><?=((!empty($azureTest['last_test_ok'])?1:0)+(!empty($geminiTest['last_test_ok'])?1:0)+(!empty($googleCloudTest['last_test_ok'])?1:0))?> / 3</strong>
      <small>últimos testes aprovados</small>
    </div>
    <div>
      <span>Telegram</span>
      <strong><?=$connectedPhone!==''?'Conectado':'Desconectado'?></strong>
      <small>conta de encaminhamento</small>
    </div>
  </div>
</section>
HTML;

    $source = str_replace($marker, $hero."\n".$marker, $source, $count);
    if ($count !== 1) {
        fwrite(STDERR, "INTEGRATIONS_UI_V2_INSERT_FAILED\n");
        exit(1);
    }

    $source = str_replace(
        '<div class="integrations-section-heading"><h2>Serviços de tradução</h2><p>Cadastre a chave de cada serviço, teste a conexão e consulte os resultados abaixo.</p></div>',
        '<div class="integrations-section-heading"><div><span class="saas-kicker">PROVEDORES DE TRADUÇÃO</span><h2>Credenciais e conectividade</h2><p>Configure cada serviço separadamente. As chaves existentes permanecem protegidas e só são substituídas quando uma nova credencial é informada.</p></div></div>',
        $source
    );

    if (@file_put_contents($path, $source) === false) {
        fwrite(STDERR, "INTEGRATIONS_UI_V2_WRITE_FAILED\n");
        exit(1);
    }
}

// Load the provider accordion only on the integrations workspace.
$source = (string)file_get_contents($path);
if (!str_contains($source, '/assets/brand/integrations-v2.js?v=1')) {
    $source = str_replace(
        '</body>',
        '<script src="/assets/brand/integrations-v2.js?v=1" defer></script></body>',
        $source
    );
    if (@file_put_contents($path, $source) === false) {
        fwrite(STDERR, "INTEGRATIONS_UI_V2_SCRIPT_WRITE_FAILED\n");
        exit(1);
    }
}

$verify = (string)file_get_contents($path);
if (!str_contains($verify, 'integrations_ui_v2') || !str_contains($verify, '/assets/brand/integrations-v2.js?v=1')) {
    fwrite(STDERR, "INTEGRATIONS_UI_V2_VERIFY_FAILED\n");
    exit(1);
}

echo "INTEGRATIONS_UI_V2_APPLIED\n";
