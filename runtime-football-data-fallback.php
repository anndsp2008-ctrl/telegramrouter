<?php declare(strict_types=1);

// TMR_FOOTBALL_DATA_FALLBACK_V1
$path = __DIR__ . '/index.php';
if (!is_file($path)) {
    fwrite(STDERR, "TMR_FOOTBALL_DATA_INDEX_MISSING\n");
    exit(1);
}

$source = (string)file_get_contents($path);

$repositoryPath = __DIR__ . '/app/Repository.php';
if (!is_file($repositoryPath)) {
    fwrite(STDERR, "TMR_FOOTBALL_DATA_REPOSITORY_MISSING\n");
    exit(1);
}
$repository = (string)file_get_contents($repositoryPath);
$oldProviders = "['openai','gemini','google_cloud','workers_ai','stake','api_football']";
$newProviders = "['openai','gemini','google_cloud','workers_ai','stake','api_football','football_data']";
if (!str_contains($repository, "'football_data'")) {
    if (substr_count($repository, $oldProviders) !== 1) {
        fwrite(STDERR, "TMR_FOOTBALL_DATA_REPOSITORY_ANCHOR_MISSING\n");
        exit(1);
    }
    $repository = str_replace($oldProviders, $newProviders, $repository);
    if (@file_put_contents($repositoryPath, $repository, LOCK_EX) === false) {
        fwrite(STDERR, "TMR_FOOTBALL_DATA_REPOSITORY_WRITE_FAILED\n");
        exit(1);
    }
}

if (
    str_contains($source, "football_data_token")
    && str_contains($source, 'value="football_data"')
    && str_contains($source, 'Data provided by football-data.org')
) {
    echo "TMR_FOOTBALL_DATA_FALLBACK_ALREADY_APPLIED\n";
    return;
}

$replaceOnce = static function(string $body, string $old, string $new, string $label): string {
    $count = substr_count($body, $old);
    if ($count !== 1) {
        throw new RuntimeException('TMR_FOOTBALL_DATA_ANCHOR_' . $label . '_COUNT_' . $count);
    }
    return str_replace($old, $new, $body);
};

try {
    $source = $replaceOnce(
        $source,
        <<<'OLD'
            } elseif($provider==='api_football'){
                $newKey=trim((string)($_POST['api_football_key']??''));
                if($newKey!=='') Repository::saveIntegration('api_football_key',$newKey);
                $notice='API-Football atualizada com segurança.';
            } else throw new RuntimeException('Provedor esportivo inválido.');
OLD,
        <<<'NEW'
            } elseif($provider==='api_football'){
                $newKey=trim((string)($_POST['api_football_key']??''));
                if($newKey!=='') Repository::saveIntegration('api_football_key',$newKey);
                $notice='API-Football atualizada com segurança.';
            } elseif($provider==='football_data'){
                $newKey=trim((string)($_POST['football_data_token']??''));
                if($newKey!=='') Repository::saveIntegration('football_data_token',$newKey);
                $notice='football-data.org atualizada como fallback.';
            } else throw new RuntimeException('Provedor esportivo inválido.');
NEW,
        'SAVE_PROVIDER'
    );

    $source = $replaceOnce(
        $source,
        <<<'OLD'
            if($provider==='stake') $override=trim((string)($_POST['stake_odds_api_key']??''));
            elseif($provider==='api_football') $override=trim((string)($_POST['api_football_key']??''));
            else throw new RuntimeException('Provedor esportivo inválido.');
OLD,
        <<<'NEW'
            if($provider==='stake') $override=trim((string)($_POST['stake_odds_api_key']??''));
            elseif($provider==='api_football') $override=trim((string)($_POST['api_football_key']??''));
            elseif($provider==='football_data') $override=trim((string)($_POST['football_data_token']??''));
            else throw new RuntimeException('Provedor esportivo inválido.');
NEW,
        'TEST_PROVIDER'
    );

    $source = $replaceOnce(
        $source,
        <<<'OLD'
$apiFootballKey=\App\SportsApiIntegration::apiFootballKey();
$stakeTest=Repository::providerTestStatus('stake');
$apiFootballTest=Repository::providerTestStatus('api_football');
OLD,
        <<<'NEW'
$apiFootballKey=\App\SportsApiIntegration::apiFootballKey();
$footballDataKey=\App\SportsApiIntegration::footballDataKey();
$stakeTest=Repository::providerTestStatus('stake');
$apiFootballTest=Repository::providerTestStatus('api_football');
$footballDataTest=Repository::providerTestStatus('football_data');
NEW,
        'PROVIDER_VARS'
    );

    $source = str_replace(
        '(!empty($apiFootballKey)?1:0); ?>',
        '(!empty($apiFootballKey)?1:0)+(!empty($footballDataKey)?1:0); ?>',
        $source,
        $countExpression
    );
    $source = str_replace(
        'Chaves <?=$integrationKeyCount?>/6',
        'Chaves <?=$integrationKeyCount?>/7',
        $source,
        $countLabel
    );
    if ($countExpression !== 1 || $countLabel !== 1) {
        throw new RuntimeException('TMR_FOOTBALL_DATA_ANCHOR_KEY_COUNT');
    }

    $oldHeading = '<div class="integrations-section-heading sports-api-heading"><div><span class="saas-kicker">APIS ESPORTIVAS</span><h2>Validação e resultados</h2><p>Substitua as credenciais usadas para validar odds na Stake e consultar partidas/resultados na API-Football. O teste pode ser executado antes de salvar.</p></div></div>';
    $newHeading = '<div class="integrations-section-heading sports-api-heading"><div><span class="saas-kicker">APIS ESPORTIVAS</span><h2>Validação e resultados</h2><p>Stake valida odds. API-Football permanece como fonte principal de resultados e football-data.org entra apenas como fallback quando a principal falhar ou não localizar a partida.</p></div></div>';
    $source = $replaceOnce($source, $oldHeading, $newHeading, 'SPORTS_HEADING');

    $card = <<<'HTML'
<section class="saas-card translation-provider-card sports-api-card">
  <div class="provider-head">
    <div><span class="saas-kicker">RESULTS FALLBACK · FREE</span><h2>football-data.org</h2><p>Fallback da API-Football para localizar partidas e obter placar/resultado quando a fonte principal falhar.</p></div>
    <span class="provider-badge <?=$footballDataKey?'is-configured':'is-empty'?>"><?=$footballDataKey?'Configurado':'Não configurado'?></span>
  </div>
  <form method="post" class="provider-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="provider" value="football_data"><div class="saas-form-grid">
    <label class="field-wide">API Token<span class="field-help"><?=$footballDataKey?'Atual: '.sh(TranslationService::maskSecret($footballDataKey)).'. Digite um novo token apenas para substituir.':'Informe o token gratuito da football-data.org.'?></span><input name="football_data_token" type="password" autocomplete="new-password" placeholder="<?=$footballDataKey?'••••••••••••••••':'Cole o token da football-data.org'?>"></label>
  </div><div class="provider-actions"><button class="saas-primary" name="action" value="save_sports_api_provider">Salvar</button><button class="saas-secondary" name="action" value="test_sports_api_provider">Testar conexão</button></div></form>
  <div class="provider-test <?=$footballDataTest?((int)$footballDataTest['last_test_ok']?'ok':'bad'):'neutral'?>"><b>Último teste</b><span><?=$footballDataTest?((int)$footballDataTest['last_test_ok']?'Conexão válida':'Falha'.(!empty($footballDataTest['last_test_error'])?' — '.sh((string)$footballDataTest['last_test_error']):(!empty($footballDataTest['last_test_http_code'])?' — HTTP '.(int)$footballDataTest['last_test_http_code']:''))):'Ainda não testado'?></span><small><?=$footballDataTest?sh(dataHoraBrasil($footballDataTest['last_test_at'])).' · '.(int)$footballDataTest['last_test_latency_ms'].' ms':'—'?></small></div>
  <p class="field-help">Plano gratuito: placares podem chegar com atraso e estatísticas avançadas não são usadas. O Router nunca converte dado ausente em zero. Data provided by football-data.org.</p>
</section>
HTML;

    $gridEnd = "</section>\n</div>\n<section class=\"translation-routing-card saas-card\">";
    if (substr_count($source, $gridEnd) !== 1) {
        throw new RuntimeException('TMR_FOOTBALL_DATA_ANCHOR_GRID_END');
    }
    $source = str_replace(
        $gridEnd,
        "</section>\n" . $card . "\n</div>\n<section class=\"translation-routing-card saas-card\">",
        $source
    );

    if (@file_put_contents($path, $source, LOCK_EX) === false) {
        throw new RuntimeException('TMR_FOOTBALL_DATA_WRITE_FAILED');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$verify = (string)file_get_contents($path);
$repositoryVerify = (string)file_get_contents($repositoryPath);
if (
    !str_contains($repositoryVerify, "'football_data'")
    || !str_contains($verify, "football_data_token")
    || !str_contains($verify, 'value="football_data"')
    || !str_contains($verify, 'SportsApiIntegration::footballDataKey()')
    || !str_contains($verify, 'Data provided by football-data.org')
) {
    fwrite(STDERR, "TMR_FOOTBALL_DATA_VERIFY_FAILED\n");
    exit(1);
}

echo "TMR_FOOTBALL_DATA_FALLBACK_APPLIED\n";
