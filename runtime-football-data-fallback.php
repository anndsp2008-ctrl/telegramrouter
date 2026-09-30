<?php declare(strict_types=1);

// TMR_FOOTBALL_DATA_FALLBACK_V2
$indexPath = __DIR__ . '/index.php';
foreach ([$indexPath] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, 'TMR_FOOTBALL_DATA_REQUIRED_MISSING ' . basename($required) . PHP_EOL);
        exit(1);
    }
}

$index = (string)file_get_contents($indexPath);
$replaceOnce = static function(string $body, string $old, string $new, string $label): string {
    $count = substr_count($body, $old);
    if ($count !== 1) {
        throw new RuntimeException('TMR_FOOTBALL_DATA_ANCHOR_' . $label . '_COUNT_' . $count);
    }
    return str_replace($old, $new, $body);
};

try {
    // Add the sports save/test actions if the sanitized snapshot predates them.
    if (!str_contains($index, "save_sports_api_provider")) {
        $handlerAnchor = "        if(\$action==='delete_rule')";
        if (!str_contains($index, $handlerAnchor)) {
            throw new RuntimeException('TMR_FOOTBALL_DATA_HANDLER_ANCHOR');
        }

        $handlers = <<<'PHP'

        if($action==='save_sports_api_provider'){
            $provider=(string)($_POST['provider']??'');
            if($provider==='stake'){
                $newKey=trim((string)($_POST['stake_odds_api_key']??''));
                if($newKey!=='') Repository::saveIntegration('stake_odds_api_key',$newKey);
                $notice='Stake atualizada com segurança.';
            } elseif($provider==='api_football'){
                $newKey=trim((string)($_POST['api_football_key']??''));
                if($newKey!=='') Repository::saveIntegration('api_football_key',$newKey);
                $notice='API-Football atualizada com segurança.';
            } elseif($provider==='football_data'){
                $newKey=trim((string)($_POST['football_data_token']??''));
                if($newKey!=='') Repository::saveIntegration('football_data_token',$newKey);
                $notice='football-data.org atualizada como fallback.';
            } else throw new RuntimeException('Provedor esportivo inválido.');
            $page='integrations';
        }

        if($action==='test_sports_api_provider'){
            $provider=(string)($_POST['provider']??'');
            $override='';
            if($provider==='stake') $override=trim((string)($_POST['stake_odds_api_key']??''));
            elseif($provider==='api_football') $override=trim((string)($_POST['api_football_key']??''));
            elseif($provider==='football_data') $override=trim((string)($_POST['football_data_token']??''));
            else throw new RuntimeException('Provedor esportivo inválido.');

            $test=\App\SportsApiIntegration::test($provider,$override);
            if(method_exists(Repository::class,'saveProviderTest')){
                Repository::saveProviderTest(
                    $provider,
                    (bool)$test['ok'],
                    (int)$test['latency_ms'],
                    $test['http_code']!==null?(int)$test['http_code']:null,
                    $test['error']!==null?(string)$test['error']:null
                );
            }
            if($test['ok']) $notice=(string)$test['message']; else $error=(string)$test['message'];
            $page='integrations';
        }

PHP;
        $index = str_replace($handlerAnchor, $handlers . $handlerAnchor, $index, $handlerCount);
        if ($handlerCount !== 1) {
            throw new RuntimeException('TMR_FOOTBALL_DATA_HANDLER_INSERT');
        }
    } elseif (!str_contains($index, "football_data_token")) {
        $index = $replaceOnce(
            $index,
            "            } elseif(\$provider==='api_football'){\n                \$newKey=trim((string)(\$_POST['api_football_key']??''));\n                if(\$newKey!=='') Repository::saveIntegration('api_football_key',\$newKey);\n                \$notice='API-Football atualizada com segurança.';\n            } else throw new RuntimeException('Provedor esportivo inválido.');",
            "            } elseif(\$provider==='api_football'){\n                \$newKey=trim((string)(\$_POST['api_football_key']??''));\n                if(\$newKey!=='') Repository::saveIntegration('api_football_key',\$newKey);\n                \$notice='API-Football atualizada com segurança.';\n            } elseif(\$provider==='football_data'){\n                \$newKey=trim((string)(\$_POST['football_data_token']??''));\n                if(\$newKey!=='') Repository::saveIntegration('football_data_token',\$newKey);\n                \$notice='football-data.org atualizada como fallback.';\n            } else throw new RuntimeException('Provedor esportivo inválido.');",
            'SAVE_FALLBACK_BRANCH'
        );
        $index = $replaceOnce(
            $index,
            "            elseif(\$provider==='api_football') \$override=trim((string)(\$_POST['api_football_key']??''));\n            else throw new RuntimeException('Provedor esportivo inválido.');",
            "            elseif(\$provider==='api_football') \$override=trim((string)(\$_POST['api_football_key']??''));\n            elseif(\$provider==='football_data') \$override=trim((string)(\$_POST['football_data_token']??''));\n            else throw new RuntimeException('Provedor esportivo inválido.');",
            'TEST_FALLBACK_BRANCH'
        );
    }

    // Ensure integration-page variables exist.
    if (!str_contains($index, '$stakeKey=\App\SportsApiIntegration::stakeKey();')) {
        $integrationMarker = "<?php elseif(\$page==='integrations'):\n";
        if (!str_contains($index, $integrationMarker)) {
            throw new RuntimeException('TMR_FOOTBALL_DATA_INTEGRATIONS_MARKER');
        }
        $vars = <<<'PHP'
$stakeKey=\App\SportsApiIntegration::stakeKey();
$apiFootballKey=\App\SportsApiIntegration::apiFootballKey();
$footballDataKey=\App\SportsApiIntegration::footballDataKey();
$stakeTest=method_exists(Repository::class,'providerTestStatus')?Repository::providerTestStatus('stake'):null;
$apiFootballTest=method_exists(Repository::class,'providerTestStatus')?Repository::providerTestStatus('api_football'):null;
$footballDataTest=method_exists(Repository::class,'providerTestStatus')?Repository::providerTestStatus('football_data'):null;
PHP;
        $index = str_replace($integrationMarker, $integrationMarker . $vars . "\n", $index, $varsCount);
        if ($varsCount !== 1) {
            throw new RuntimeException('TMR_FOOTBALL_DATA_VARS_INSERT');
        }
    } elseif (!str_contains($index, '$footballDataKey=')) {
        $index = $replaceOnce(
            $index,
            '$apiFootballKey=\App\SportsApiIntegration::apiFootballKey();',
            '$apiFootballKey=\App\SportsApiIntegration::apiFootballKey();' . "\n" .
            '$footballDataKey=\App\SportsApiIntegration::footballDataKey();',
            'FALLBACK_KEY_VAR'
        );
        $index = $replaceOnce(
            $index,
            "$apiFootballTest=Repository::providerTestStatus('api_football');",
            "$apiFootballTest=Repository::providerTestStatus('api_football');\n" .
            "$footballDataTest=method_exists(Repository::class,'providerTestStatus')?Repository::providerTestStatus('football_data'):null;",
            'FALLBACK_TEST_VAR'
        );
    }

    // Build a complete sports section. If the old snapshot has no sports UI,
    // insert all three cards; if it already has Stake/API-Football, append only
    // football-data.org.
    $footballDataCard = <<<'HTML'
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

    if (!str_contains($index, 'value="football_data"')) {
        if (!str_contains($index, 'sports-api-provider-grid')) {
            $sportsSection = <<<'HTML'
<div class="integrations-section-heading sports-api-heading"><div><span class="saas-kicker">APIS ESPORTIVAS</span><h2>Validação e resultados</h2><p>Stake valida odds. API-Football permanece como fonte principal de resultados e football-data.org entra apenas como fallback quando a principal falhar ou não localizar a partida.</p></div></div>
<div class="translation-provider-grid sports-api-provider-grid">
<section class="saas-card translation-provider-card sports-api-card">
  <div class="provider-head">
    <div><span class="saas-kicker">STAKE SPORTS DATA</span><h2>Stake</h2><p>Validação de evento, mercado, linha e odd antes da geração do card.</p></div>
    <span class="provider-badge <?=$stakeKey?'is-configured':'is-empty'?>"><?=$stakeKey?'Configurado':'Não configurado'?></span>
  </div>
  <form method="post" class="provider-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="provider" value="stake"><div class="saas-form-grid">
    <label class="field-wide">API Key<span class="field-help"><?=$stakeKey?'Atual: '.sh(TranslationService::maskSecret($stakeKey)).'. Digite uma nova chave apenas para substituir.':'Informe a chave da Stake Sports Data API.'?></span><input name="stake_odds_api_key" type="password" autocomplete="new-password" placeholder="<?=$stakeKey?'••••••••••••••••':'Cole a API Key da Stake'?>"></label>
  </div><div class="provider-actions"><button class="saas-primary" name="action" value="save_sports_api_provider">Salvar</button><button class="saas-secondary" name="action" value="test_sports_api_provider">Testar conexão</button></div></form>
  <div class="provider-test <?=$stakeTest?((int)$stakeTest['last_test_ok']?'ok':'bad'):'neutral'?>"><b>Último teste</b><span><?=$stakeTest?((int)$stakeTest['last_test_ok']?'Conexão válida':'Falha'.(!empty($stakeTest['last_test_error'])?' — '.sh((string)$stakeTest['last_test_error']):(!empty($stakeTest['last_test_http_code'])?' — HTTP '.(int)$stakeTest['last_test_http_code']:''))):'Ainda não testado'?></span><small><?=$stakeTest?sh(dataHoraBrasil($stakeTest['last_test_at'])).' · '.(int)$stakeTest['last_test_latency_ms'].' ms':'—'?></small></div>
</section>
<section class="saas-card translation-provider-card sports-api-card">
  <div class="provider-head">
    <div><span class="saas-kicker">API-SPORTS</span><h2>API-Football</h2><p>Fonte principal de partidas, horários, placares e estatísticas usadas pelo módulo de resultados.</p></div>
    <span class="provider-badge <?=$apiFootballKey?'is-configured':'is-empty'?>"><?=$apiFootballKey?'Configurado':'Não configurado'?></span>
  </div>
  <form method="post" class="provider-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="provider" value="api_football"><div class="saas-form-grid">
    <label class="field-wide">API Key<span class="field-help"><?=$apiFootballKey?'Atual: '.sh(TranslationService::maskSecret($apiFootballKey)).'. Digite uma nova chave apenas para substituir.':'Informe a chave da API-Football.'?></span><input name="api_football_key" type="password" autocomplete="new-password" placeholder="<?=$apiFootballKey?'••••••••••••••••':'Cole a API Key da API-Football'?>"></label>
  </div><div class="provider-actions"><button class="saas-primary" name="action" value="save_sports_api_provider">Salvar</button><button class="saas-secondary" name="action" value="test_sports_api_provider">Testar conexão</button></div></form>
  <div class="provider-test <?=$apiFootballTest?((int)$apiFootballTest['last_test_ok']?'ok':'bad'):'neutral'?>"><b>Último teste</b><span><?=$apiFootballTest?((int)$apiFootballTest['last_test_ok']?'Conexão válida':'Falha'.(!empty($apiFootballTest['last_test_error'])?' — '.sh((string)$apiFootballTest['last_test_error']):(!empty($apiFootballTest['last_test_http_code'])?' — HTTP '.(int)$apiFootballTest['last_test_http_code']:''))):'Ainda não testado'?></span><small><?=$apiFootballTest?sh(dataHoraBrasil($apiFootballTest['last_test_at'])).' · '.(int)$apiFootballTest['last_test_latency_ms'].' ms':'—'?></small></div>
</section>
HTML;
            $sportsSection .= "\n" . $footballDataCard . "\n</div>\n";

            $translationHeading = '<div class="integrations-section-heading"><div><span class="saas-kicker">PROVEDORES DE TRADUÇÃO';
            $insertPos = strpos($index, $translationHeading);
            if ($insertPos === false) {
                $routingPos = strpos($index, '<section class="translation-routing-card');
                if ($routingPos === false) {
                    throw new RuntimeException('TMR_FOOTBALL_DATA_SPORTS_UI_ANCHOR');
                }
                $insertPos = $routingPos;
            }
            $index = substr($index, 0, $insertPos) . $sportsSection . substr($index, $insertPos);
        } else {
            $gridStart = strpos($index, '<div class="translation-provider-grid sports-api-provider-grid">');
            if ($gridStart === false) {
                throw new RuntimeException('TMR_FOOTBALL_DATA_SPORTS_GRID_START');
            }
            $nextSection = strpos($index, '<section class="translation-routing-card', $gridStart);
            if ($nextSection === false) {
                throw new RuntimeException('TMR_FOOTBALL_DATA_SPORTS_GRID_END');
            }
            $gridEnd = strrpos(substr($index, $gridStart, $nextSection - $gridStart), '</div>');
            if ($gridEnd === false) {
                throw new RuntimeException('TMR_FOOTBALL_DATA_SPORTS_GRID_CLOSE');
            }
            $absoluteEnd = $gridStart + $gridEnd;
            $index = substr($index, 0, $absoluteEnd) . $footballDataCard . "\n" . substr($index, $absoluteEnd);
        }
    }

    if (@file_put_contents($indexPath, $index, LOCK_EX) === false) {
        throw new RuntimeException('TMR_FOOTBALL_DATA_INDEX_WRITE_FAILED');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$indexVerify = (string)file_get_contents($indexPath);
if (
    !str_contains($indexVerify, "football_data_token")
    || !str_contains($indexVerify, 'value="football_data"')
    || !str_contains($indexVerify, 'SportsApiIntegration::footballDataKey()')
    || !str_contains($indexVerify, 'sports-api-provider-grid')
    || !str_contains($indexVerify, 'Data provided by football-data.org')
) {
    fwrite(STDERR, "TMR_FOOTBALL_DATA_VERIFY_FAILED\n");
    exit(1);
}

echo "TMR_FOOTBALL_DATA_FALLBACK_APPLIED\n";
