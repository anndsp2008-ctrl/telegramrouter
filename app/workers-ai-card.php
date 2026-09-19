<?php declare(strict_types=1);
// Included only in the authenticated integrations page.
$workersAIKey=\App\WorkersAITranslation::token();
$workersAIAccount=\App\WorkersAITranslation::account();
$workersAIModel=\App\WorkersAITranslation::model();
$workersAIConfigured=$workersAIKey!=='' && $workersAIAccount!=='';
$workersAITest=Repository::providerTestStatus('workers_ai');
$workersAIStats=Repository::translationProviderStats('workers_ai');
?>
<section class="saas-card translation-provider-card workers-ai-provider-card">
  <div class="saas-card-head">
    <div><span class="saas-kicker">CLOUDFLARE AI</span><h2>Workers AI</h2>
      <p>Tradução de textos por IA com detecção automática de idioma e modelo configurável.</p></div>
    <span class="form-status <?=$workersAIConfigured?'is-configured':'is-empty'?>">
      <i></i><?=$workersAIConfigured?'Configurado':'Não configurado'?></span>
  </div>
  <form method="post" class="provider-form">
    <input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>">
    <input type="hidden" name="provider" value="workers_ai">
    <div class="saas-form-grid">
      <label class="field-wide">API Token<span class="field-help">
        <?=$workersAIKey?'Atual: '.sh(TranslationService::maskSecret($workersAIKey)).'. Digite um novo token somente para substituir.':'Informe seu token do Cloudflare Workers AI.'?>
      </span>
      <input type="password" name="workers_ai_api_token" autocomplete="new-password"
        placeholder="<?=$workersAIKey?'••••••••••••••••':'Cole seu token do Workers AI'?>">
      </label>
      <label class="field-wide">ID da conta<span class="field-help">Account ID do painel Cloudflare.</span>
        <input name="workers_ai_account_id" value="<?=sh($workersAIAccount)?>" maxlength="32"
          autocomplete="off" placeholder="32 caracteres hexadecimais">
      </label>
      <label class="field-wide">Modelo<span class="field-help">Modelo de geração de texto para tradução.</span>
        <input name="workers_ai_model" value="<?=sh($workersAIModel)?>"
          autocomplete="off" placeholder="@cf/meta/llama-3.1-8b-instruct-fp8">
      </label>
    </div>
    <div class="provider-actions">
      <button class="saas-primary" name="action" value="save_translation_provider">Salvar</button>
      <button class="saas-secondary" name="action" value="test_translation_provider">Testar conexão</button>
    </div>
  </form>
  <div class="provider-test <?=$workersAITest?((int)$workersAITest['last_test_ok']?'ok':'bad'):'neutral'?>">
    <b>Último teste</b><span><?=$workersAITest?((int)$workersAITest['last_test_ok']?'Conexão válida':'Falha'):'Ainda não testado'?></span>
    <small><?=$workersAITest?sh(dataHoraBrasil($workersAITest['last_test_at'])).' · '.(int)$workersAITest['last_test_latency_ms'].' ms':'—'?></small>
    <?php if($workersAITest&&!$workersAITest['last_test_ok']&&!empty($workersAITest['last_test_error'])):?>
    <em><?=sh($workersAITest['last_test_error'])?></em><?php endif;?>
  </div>
  <div class="provider-stats">
    <div><span>Traduções</span><b><?=sh($workersAIStats['total'])?></b></div>
    <div><span>Sucessos</span><b><?=sh($workersAIStats['successful'])?></b></div>
    <div><span>Falhas</span><b><?=sh($workersAIStats['failures'])?></b></div>
    <div><span>Latência média</span><b><?=sh($workersAIStats['avg_latency'])?> ms</b></div>
    <div><span>Última latência</span><b><?=sh($workersAIStats['last_latency'])?> ms</b></div>
    <div><span>Último uso</span><b><?=sh(dataHoraBrasil($workersAIStats['last_use']))?></b></div>
  </div>
</section>