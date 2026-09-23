<?php if (!isset($googleCloudStats)) { http_response_code(404); exit; } ?>
<section class="saas-card translation-provider-card">
  <div class="saas-card-head"><div><span class="saas-kicker">GOOGLE CLOUD</span><h2>Google Cloud Translation</h2><p>Tradução de texto com detecção automática do idioma.</p></div><span class="form-status <?=$googleCloudKey?'is-configured':'is-empty'?>"><i></i><?=$googleCloudKey?'Configurado':'Não configurado'?></span></div>
  <form method="post" class="provider-form">
    <input type="hidden" name="csrf" value="<?=sh(\App\Auth::csrf())?>">
    <input type="hidden" name="provider" value="google_cloud">
    <div class="saas-form-grid"><label class="field-wide">API Key
      <span class="field-help"><?=$googleCloudKey?'Atual: '.sh(\App\TranslationService::maskSecret($googleCloudKey)).'. Deixe vazio para manter a chave.':'Informe a chave do projeto com Cloud Translation API e faturamento ativados.'?></span>
      <input name="google_cloud_api_key" type="password" autocomplete="new-password" placeholder="Cole a chave do Google Cloud">
    </label></div>
    <p class="field-help">Basic v2 · NMT. A franquia gratuita depende do consumo do projeto; o Google cobra o excedente. O Router não impõe um bloqueio mensal de gastos.</p>
    <div class="provider-actions"><button class="saas-primary" name="action" value="save_translation_provider">Salvar</button><button class="saas-secondary" name="action" value="test_translation_provider">Testar conexão</button></div>
  </form>
  <div class="provider-test <?=$googleCloudTest?((int)$googleCloudTest['last_test_ok']?'ok':'bad'):'neutral'?>">
    <b>Último teste</b><span><?=$googleCloudTest?((int)$googleCloudTest['last_test_ok']?'Conexão válida':'Falha'):'Ainda não testado'?></span>
    <small><?=$googleCloudTest?sh(dataHoraBrasil($googleCloudTest['last_test_at'])).' · '.(int)$googleCloudTest['last_test_latency_ms'].' ms':'—'?></small>
    <?php if($googleCloudTest&&!$googleCloudTest['last_test_ok']&&!empty($googleCloudTest['last_test_error'])):?><em><?=sh($googleCloudTest['last_test_error'])?></em><?php endif;?>
  </div>
  <div class="provider-stats">
    <?php foreach(['total'=>'Traduções','successful'=>'Sucessos','failures'=>'Falhas','avg_latency'=>'Latência média','last_latency'=>'Última latência'] as $key=>$label):?>
      <div><span><?=sh($label)?></span><b><?=sh($googleCloudStats[$key])?><?=str_contains($key,'latency')?' ms':''?></b></div>
    <?php endforeach;?>
    <div><span>Último uso</span><b><?=sh(dataHoraBrasil($googleCloudStats['last_use']))?></b></div>
  </div>
</section>
