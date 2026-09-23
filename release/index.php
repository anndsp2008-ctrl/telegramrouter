<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use App\Auth; use App\Repository; use App\TranslationService;
Auth::requireLogin();
$saudacaoHora=(int)(new \DateTimeImmutable('now',new \DateTimeZone('America/Sao_Paulo')))->format('G');
$saudacaoNome=($saudacaoHora<12?'Bom dia':($saudacaoHora<18?'Boa tarde':'Boa noite')).', Anderson';
$page=$_GET['page']??'dashboard'; $error=null; $notice=null; $rulesPage=max(1,(int)($_GET['rules_page']??1)); $eventsPage=max(1,(int)($_GET['events_page']??1));
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        Auth::verifyCsrf($_POST['csrf']??null);
        $action=(string)($_POST['action']??'');
        if($action==='logout'){Auth::logout();header('Location:/login.php');exit;}

        if($action==='save_translation_provider'){
            $provider=(string)($_POST['provider']??'');
            if($provider==='azure'){
                $newKey=trim((string)($_POST['azure_api_key']??''));
                if($newKey!=='') Repository::saveIntegration('azure_translator_api_key',$newKey);
                $region=trim((string)($_POST['azure_region']??''));
                $endpoint=trim((string)($_POST['azure_endpoint']??''));
                Repository::saveIntegration('azure_translator_region',$region);
                Repository::saveIntegration('azure_translator_endpoint',$endpoint!==''?$endpoint:'https://api.cognitive.microsofttranslator.com');
                $notice='Microsoft Azure Translator atualizado com segurança.';
            } elseif($provider==='gemini'){
                $newKey=trim((string)($_POST['gemini_api_key']??''));
                if($newKey!=='') Repository::saveIntegration('gemini_api_key',$newKey);
                $model=trim((string)($_POST['gemini_model']??'gemini-3.6-flash'));
                if(!preg_match('/^[A-Za-z0-9._-]{2,96}$/',$model)) throw new RuntimeException('Nome do modelo Gemini inválido.');
                Repository::saveIntegration('gemini_model',$model);
                $notice='Google Gemini atualizado com segurança.';
            } elseif($provider==='google_cloud'){
                $newKey=trim((string)($_POST['google_cloud_api_key']??''));
                if($newKey!=='') Repository::saveIntegration('google_cloud_api_key',$newKey);
                $notice='Google Cloud Translation atualizado com segurança.';
            } elseif($provider==='workers_ai'){
                $newToken=trim((string)($_POST['workers_ai_api_token']??''));
                $account=trim((string)($_POST['workers_ai_account_id']??''));
                $model=trim((string)($_POST['workers_ai_model']??''));
                if($account!==''&&!preg_match('/^[a-f0-9]{32}$/Di',$account)) throw new RuntimeException('ID da conta Cloudflare inválido.');
                if($model!==''&&!\App\WorkersAITranslation::validModel($model)) throw new RuntimeException('Modelo Workers AI inválido.');
                if($newToken!=='') Repository::saveIntegration('workers_ai_api_token',$newToken);
                if($account!=='') Repository::saveIntegration('workers_ai_account_id',$account);
                if($model!=='') Repository::saveIntegration('workers_ai_model',$model);
                $notice='Workers AI atualizado com segurança.';
            } else throw new RuntimeException('Provedor de tradução inválido.');
            $page='integrations';
        }

        if($action==='test_translation_provider'){
            $provider=(string)($_POST['provider']??'');
            $overrides=[];
            if($provider==='azure'){
                foreach(['azure_api_key','azure_region','azure_endpoint'] as $field){$value=trim((string)($_POST[$field]??''));if($value!=='')$overrides[$field]=$value;}
            } elseif($provider==='gemini'){
                foreach(['gemini_api_key','gemini_model'] as $field){$value=trim((string)($_POST[$field]??''));if($value!=='')$overrides[$field]=$value;}
            }
            if($provider==='google_cloud'){
                $value=trim((string)($_POST['google_cloud_api_key']??''));
                if($value!=='') $overrides['google_cloud_api_key']=$value;
            }
            if($provider==='workers_ai'){
                foreach(['workers_ai_api_token','workers_ai_account_id','workers_ai_model'] as $field){
                    $value=trim((string)($_POST[$field]??''));
                    if($value!=='')$overrides[$field]=$value;
                }
            }
            $test=TranslationService::testProvider($provider,$overrides);
            if($test['ok']) $notice=$test['message']; else $error=$test['message'];
            $page='integrations';
        }

        if($action==='save_translation_routing'){
            Repository::saveTranslationRouting((string)($_POST['translation_primary_provider']??''),(string)($_POST['translation_fallback_provider']??'none'));
            $notice='Prioridade e fallback dos tradutores atualizados.';
            $page='integrations';
        }

        if($action==='delete_rule'){Repository::deleteRule((int)$_POST['id']);$notice='Regra removida com sucesso.';}
        if($action==='save_rule'){
            Repository::saveRule(array_merge($_POST,['remove_links'=>isset($_POST['remove_links']),'remove_emojis'=>isset($_POST['remove_emojis']),'translation_enabled'=>isset($_POST['translation_enabled']),'translation_fallback_original'=>isset($_POST['translation_fallback_original']),'enabled'=>isset($_POST['enabled'])]));
            \App\SmartFormatting::saveForRule((int)\App\Database::pdo()->lastInsertId(),$_POST);$notice='Nova regra publicada.';
        }
        if($action==='update_rule'){
            Repository::updateRule((int)$_POST['id'],array_merge($_POST,['remove_links'=>isset($_POST['remove_links']),'remove_emojis'=>isset($_POST['remove_emojis']),'translation_enabled'=>isset($_POST['translation_enabled']),'translation_fallback_original'=>isset($_POST['translation_fallback_original']),'enabled'=>isset($_POST['enabled'])]));
            \App\SmartFormatting::saveForRule((int)$_POST['id'],$_POST);$notice='Regra atualizada e aplicada ao trabalhador.';
        }
    }catch(Throwable $e){$error=TranslationService::sanitizeError($e->getMessage());}
}
$credentials=Repository::credentials();$connectedPhone=(string)($credentials['telegram_phone']??'');if($connectedPhone!==''&&$connectedPhone[0]!=='+')$connectedPhone='+'.$connectedPhone;$rulesTotal=Repository::rulesTotal();$rulesPage=min($rulesPage,max(1,(int)ceil($rulesTotal/6)));$rules=Repository::rules($rulesPage,6);$events=Repository::events($eventsPage,20);$eventsTotal=Repository::eventsTotal();$stats=Repository::overview();$status=Repository::workerStatus();$editRule=isset($_GET['edit'])?Repository::rule((int)$_GET['edit']):null;$formRule=$editRule?:['source_chat'=>'','trigger_text'=>'','destination_chat'=>'','media_mode'=>'previous_photo','translation_provider'=>Repository::translationPrimaryProvider(),'translation_target_language'=>'pt-BR','remove_links'=>1,'remove_emojis'=>1,'custom_removals'=>'','translation_enabled'=>0,'translation_fallback_original'=>1,'enabled'=>1];
function sh(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function paginas(int $page,int $total,string $param,int $perPage=20):string { $pages=max(1,(int)ceil($total/$perPage)); if($pages<=1)return ''; $out='<nav class="pagination" aria-label="Navegação de páginas">'; for($i=1;$i<=$pages;$i++){ $query=$_GET; $query[$param]=$i; $out.='<a class="'.($i===$page?'current':'').'" href="?'.sh(http_build_query($query)).'">'.$i.'</a>'; } return $out.'</nav>'; }
$active=$page==='dashboard'?'dashboard':$page;
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Telegram Router · Workspace</title><link rel="stylesheet" href="/assets/saas.css"><link rel="stylesheet" href="/assets/translation-v2.css?v=2"><link rel="stylesheet" href="/assets/responsive.css?v=4">

<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMR">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/pwa/apple-touch-icon.png?v=1">
<script defer src="/assets/pwa.js?v=1"></script>
<style id="rules-layout-v3">
.rules-panel{display:flex!important;flex-direction:column;min-height:1100px;box-sizing:border-box}
.rules-panel .rule-card-bottom{display:flex!important;align-items:center!important;justify-content:space-between;gap:12px;flex-wrap:wrap}
.rules-panel .rule-tags{display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.rules-panel .rule-card .rule-actions{display:flex!important;align-items:center!important;gap:10px;margin:0 0 0 auto!important}
.rules-panel .rule-card .rule-actions .routing-delete{display:flex!important;align-self:center!important;align-items:center!important;margin:0!important;padding:0!important}
.rules-panel .rule-card .rule-actions .rule-edit,.rules-panel .rule-card .rule-actions .saas-danger{display:inline-flex!important;align-items:center!important;justify-content:center!important;align-self:center!important;height:36px!important;min-width:64px;box-sizing:border-box!important;margin:0!important;padding:0 12px!important;line-height:1!important;font-size:11px!important}
.rules-panel .pagination{display:flex;justify-content:center;align-items:center;flex-wrap:wrap;gap:8px;margin-top:auto!important;padding-top:20px;min-height:44px}
.rules-panel .pagination a{display:inline-flex;justify-content:center;align-items:center;width:36px;height:36px;box-sizing:border-box}
@media(max-width:700px){.rules-panel{min-height:1450px}}
</style><style id="tmr-icons-v1">
.tmr-icon{display:inline-block;vertical-align:middle;flex-shrink:0;pointer-events:none}
.saas-nav .nav-icon{display:inline-flex!important;align-items:center;justify-content:center;width:30px;height:30px;flex:0 0 30px;border-radius:9px;background:rgba(137,160,180,.06);color:#8da5b9;transition:background .18s,color .18s}
.saas-nav a:hover .nav-icon,.saas-nav a.active .nav-icon{background:rgba(118,243,228,.12);color:#76f3e4}
.treatment-icon,.saas-metric-icon,.rules-empty-icon,.hero-symbol{display:inline-flex;align-items:center;justify-content:center}
.treatment-icon .tmr-icon,.saas-metric-icon .tmr-icon{width:22px;height:22px}
.rules-empty-icon .tmr-icon,.hero-symbol .tmr-icon{width:28px;height:28px}
.rule-actions .tmr-icon{width:14px;height:14px;margin-right:6px}
</style><style id="tmr-scrollbar-v1">
/* Native scrolling: standard properties for Firefox, detailed skin for WebKit/Blink. */
@media (forced-colors: none){
 *{scrollbar-width:thin;scrollbar-color:#408f88 #0b141b}
 @supports selector(::-webkit-scrollbar){
  *{scrollbar-width:auto;scrollbar-color:auto}
  ::-webkit-scrollbar{width:10px}
  ::-webkit-scrollbar-track:vertical{background:#0b141b;border-radius:999px}
  ::-webkit-scrollbar-thumb:vertical{background:linear-gradient(180deg,#408f88,#57b5a9);border:2px solid #0b141b;border-radius:999px;min-height:44px}
  ::-webkit-scrollbar-thumb:vertical:hover{background:#76f3e4}
  ::-webkit-scrollbar-thumb:vertical:active{background:#a4fff1}
  ::-webkit-scrollbar-button:vertical{display:none}
 }
}
</style><link rel="stylesheet" href="/assets/brand/workers-ai-provider.css?v=5"><link rel="stylesheet" href="/assets/brand/brand.css?v=2"><link rel="stylesheet" href="/assets/brand/mobile-shell.css?v=2"><link rel="stylesheet" href="/assets/brand/integrations-v10.css?v=8&workers-dot=5"><link rel="stylesheet" href="/assets/brand/activity-status-badges.css?v=4"><link rel="stylesheet" href="/assets/brand/horizontal-scrollbar.css?v=1"><link rel="stylesheet" href="/assets/brand/mobile-visual-audit.css?v=15"><link rel="stylesheet" href="/assets/brand/service-status-responsive.css?v=1"><link rel="stylesheet" href="/assets/brand/connect-responsive.css?v=2"><link rel="stylesheet" href="/assets/brand/orchestration-responsive.css?v=1"><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3"><link rel="manifest" href="/manifest.webmanifest?v=2"><meta name="theme-color" content="#0B1220"><link rel="stylesheet" href="/assets/brand/mobile-bottom-navigation.css?v=2">
<style id="tmr-mobile-bottom-nav-critical">
@media (max-width:1024px){
  .saas-shell>.saas-sidebar{display:none!important}
  .saas-shell> .saas-main{margin-left:0!important;width:100%!important;max-width:100%!important;padding-bottom:calc(105px + env(safe-area-inset-bottom,0px))!important}
  nav.tmr-mobile-navigation{
    display:grid!important;position:fixed!important;
    grid-template-columns:repeat(5,minmax(0,1fr));
    left:clamp(8px,2vw,20px);right:clamp(8px,2vw,20px);
    bottom:calc(8px + env(safe-area-inset-bottom,0px));
    z-index:1100;min-height:78px;padding:7px 6px;
    background:#151f2b;border:1px solid rgba(142,163,184,.16);
    border-radius:15px;box-sizing:border-box;
  }
}
@media (min-width:1025px){nav.tmr-mobile-navigation{display:none!important}}
</style><link rel="stylesheet" href="/assets/brand/mobile-app-header.css?v=2"><link rel="stylesheet" href="/assets/brand/logout-icon.css?v=1"><link rel="stylesheet" href="/assets/brand/smart-format.css?v=1"><link rel="stylesheet" href="/assets/brand/smart-format.css?v=1"></head><body><div class="saas-shell"><aside class="saas-sidebar"><div class="saas-brand"><span class="saas-logo brand-mark"><img src="/assets/brand/mark.svg?v=1" alt="" aria-hidden="true"></span><div><b>Telegram Router</b><small>Automação inteligente</small></div></div><div class="saas-nav-label">PAINEL</div><nav class="saas-nav"><a class="<?=$active==='dashboard'?'active':''?>" href="/?page=dashboard"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/></svg></span>Visão geral</a><a class="<?=$active==='rules'?'active':''?>" href="/?page=rules"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v10a2 2 0 0 0 2 2h4M18 17V7a2 2 0 0 0-2-2h-4m-2 12 2 2-2 2m4-18-2 2 2 2"/></svg></span>Regras de roteamento</a><a href="/ai-learning.php"><span class="nav-icon">✦</span>Aprendizado da IA</a><a class="<?=$active==='events'?'active':''?>" href="/?page=events"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>Atividade</a><a class="<?=$active==='integrations'?'active':''?>" href="/?page=integrations"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 3v5m6-5v5M7 8h10v4a5 5 0 0 1-10 0ZM12 17v4"/></svg></span>Integrações</a><a href="/connect.php"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m21 3-7 18-4-7-7-4Zm0 0L10 14"/></svg></span>Telegram</a></nav><div class="saas-bottom"><div class="saas-status"><i></i>Serviço protegido</div></div></aside><div class="saas-main"><header class="saas-topbar"><div class="tmr-app-header-brand" aria-label="TelegramRouter">
  <span class="tmr-app-brand-symbol"><img src="/assets/brand/mark.svg?v=1" alt=""></span>
  <span class="tmr-app-brand-name"><b>Telegram<span>Router</span></b><small>Conecte. Direcione. Automatize.</small></span>
</div><div class="saas-user"><span class="saas-avatar">AN</span><span><?=sh($saudacaoNome)?></span><?php if($connectedPhone!==''): ?><span class="connected-phone">Telegram: <?=sh($connectedPhone)?></span><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="action" value="logout"><button class="saas-logout" type="submit" aria-label="Sair" title="Sair"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M14 16l4-4-4-4"/><path d="M18 12H8"/></svg></button></form></div></header><nav class="tmr-mobile-navigation" aria-label="Navegação principal em celulares e tablets">
  <a href="/?page=dashboard" class="<?=($active==='dashboard'?'is-active':'')?>" <?=($active==='dashboard'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg></span><span>Visão geral</span></a>
  <a href="/?page=rules" class="<?=($active==='rules'?'is-active':'')?>" <?=($active==='rules'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01" stroke-width="3"/></svg></span><span>Regras</span></a>
  <a class="tmr-nav-create" href="/?page=rules#nova-regra" aria-label="Criar nova regra"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span><span>Nova regra</span></a>
  <a href="/?page=events" class="<?=($active==='events'?'is-active':'')?>" <?=($active==='events'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></span><span>Atividade</span></a>
  <details class="tmr-nav-more">
    <summary aria-label="Mais módulos"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg></span><span>Mais</span></summary>
    <div class="tmr-more-panel">
      <a href="/?page=integrations" class="<?=($active==='integrations'?'is-active':'')?>" <?=($active==='integrations'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3v6M16 3v6M6 9h12v3a6 6 0 0 1-12 0V9zM12 18v3"/></svg></span><span>Integrações</span></a>
      <a href="/connect.php"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 7a7 7 0 0 1 10 0M4 4a11 11 0 0 1 16 0M10 10a3 3 0 0 1 4 0"/><circle cx="12" cy="15" r="1.5"/></svg></span><span>Conectar Telegram</span></a>
      <a href="/ai-learning.php"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3l1.8 6.2L20 11l-6.2 1.8L12 19l-1.8-6.2L4 11l6.2-1.8L12 3z"/><path d="M19 17v4M17 19h4"/></svg></span><span>Aprendizado da IA</span></a>
      <a href="/reset.php"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v6h6"/><path d="M12 8v4M12 16h.01"/></svg></span><span>Reset de dados</span></a>
    </div>
  </details>
</nav><main class="saas-content"><?php if($error):?><div class="saas-flash error"><?=sh($error)?></div><?php endif;?><?php if($notice):?><div class="saas-flash success"><?=sh($notice)?></div><?php endif;?><?php if($page==='rules'):?><div class="saas-heading"><div><span class="saas-kicker">AUTOMAÇÃO</span><h1>Regras de roteamento</h1><p>Defina exatamente quando uma mensagem deve ser tratada e para onde será enviada.</p></div><div class="saas-heading-actions"><a class="saas-secondary" href="/?page=dashboard">← Visão geral</a><a class="saas-primary" href="#nova-regra">+ Criar regra</a></div></div>
<section class="routing-flow"><div><span class="flow-number">01</span><b>Origem</b><small>Onde a mensagem chega</small></div><span class="flow-arrow">→</span><div><span class="flow-number">02</span><b>Gatilho</b><small>O que ativa a regra</small></div><span class="flow-arrow">→</span><div><span class="flow-number">03</span><b>Destino</b><small>Para onde enviar</small></div></section>
<div class="rules-columns"><section class="saas-card saas-form-card routing-form" id="nova-regra"><div class="saas-card-head"><div><span class="saas-kicker"><?= $editRule?'EDIÇÃO DE REGRA':'NOVA CONFIGURAÇÃO' ?></span><h2><?= $editRule?'Atualizar regra de roteamento':'Quando esta regra for acionada' ?></h2><p>Os campos abaixo serão avaliados pelo trabalhador em tempo real.</p></div><span class="form-status"><i></i>Pronta para ativar</span></div><form method="post"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="action" value="<?=$editRule?'update_rule':'save_rule'?>"><?php if($editRule):?><input type="hidden" name="id" value="<?=sh($editRule['id'])?>"><?php endif;?><div class="form-section-title">01 · Identificação dos canais</div><div class="saas-form-grid"><label>Canal ou grupo de origem<span class="field-help">Aceita ID numérico, @username ou nome público.</span><input name="source_chat" value="<?=sh($formRule['source_chat'])?>" placeholder="@canal_origem ou -100…" required></label><label>Canal ou grupo de destino<span class="field-help">A conta Telegram precisa ter acesso ao destino.</span><input name="destination_chat" value="<?=sh($formRule['destination_chat'])?>" placeholder="@meu_canal ou -100…" required></label></div><div class="form-section-title">02 · Condição de acionamento</div><div class="saas-form-grid"><label class="field-wide">Texto que deve aparecer na mensagem<span class="field-help">A comparação não diferencia letras maiúsculas e minúsculas.</span><input name="trigger_text" value="<?=sh($formRule['trigger_text'])?>" placeholder="Ex.: CUPOM, OFERTA, SINAL…" required></label><label class="activation-field">Estado inicial<span class="field-help">Você pode pausar a regra depois na configuração.</span><span class="activation-toggle"><input type="checkbox" name="enabled" checked> Ativar imediatamente</span></label></div><div class="form-section-title">03 · Tratamento do conteúdo</div>
<div class="content-treatment">
  <div class="treatment-block treatment-media">
    <div class="treatment-block-head"><span class="treatment-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1"/><path d="m3 17 5-5 4 4 4-6 5 7"/></svg></span><div><b>Formato enviado</b><small>Escolha o conteúdo que chegará ao destino.</small></div></div>
    <label>Conteúdo enviado<select name="media_mode"><option value="previous_photo" <?=$formRule['media_mode']==='previous_photo'?'selected':''?>>Imagem Anterior + Legenda</option><option value="current_media" <?=$formRule['media_mode']==='current_media'?'selected':''?>>Mídia da mensagem + legenda</option><option value="text_only" <?=$formRule['media_mode']==='text_only'?'selected':''?>>Somente texto</option></select></label>
  </div>
  <div class="treatment-block treatment-translation">
    <div class="treatment-block-head"><span class="treatment-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 5h12M9 3v2m4 0c-1 6-4 9-9 11m1-9c1 4 4 7 8 9m1 5 4-11 4 11m-6-4h4"/></svg></span><div><b>Tradução</b><small>Converta a mensagem antes do envio.</small></div></div>
    <label>Provedor de tradução<select name="translation_provider"><option value="azure" <?=$formRule['translation_provider']==='azure'?'selected':''?>>Microsoft Azure Translator</option><option value="gemini" <?=$formRule['translation_provider']==='gemini'?'selected':''?>>Google Gemini</option><option value="google_cloud" <?=$formRule['translation_provider']==='google_cloud'?'selected':''?>>Google Cloud Translation</option><option value="workers_ai" <?=($formRule['translation_provider']==='workers_ai')?'selected':''?>>Cloudflare Workers AI</option></select></label>
    <label>Idioma de destino<input name="translation_target_language" value="<?=sh($formRule['translation_target_language'])?>" placeholder="pt-BR"></label>
  </div>
  <div class="treatment-block treatment-removals">
    <div class="treatment-block-head"><span class="treatment-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m4 13 9-9a2 2 0 0 1 3 0l5 5a2 2 0 0 1 0 3l-8 8H8l-4-4a2 2 0 0 1 0-3Zm3-3 9 9M13 20h8"/></svg></span><div><b>Remoções personalizadas</b><small>Separe os textos ou números com ponto e vírgula (;). Todos os itens serão retirados da mensagem.</small></div></div>
    <label>Textos ou números para remover<textarea name="custom_removals" rows="4" placeholder="Exemplo: Assine nosso canal; 12345; PATROCINADO"><?=sh($formRule['custom_removals']??'')?></textarea></label>
  </div>
  <div class="treatment-block treatment-cleaning">
    <div class="treatment-block-head"><span class="treatment-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5ZM20 2v4m-2-2h4"/></svg></span><div><b>Opções de limpeza</b><small>Defina quais elementos serão removidos automaticamente.</small></div></div>
    <div class="treatment-checks"><label><input type="checkbox" name="remove_links" <?=!empty($formRule['remove_links'])?'checked':''?>> Remover links</label><label><input type="checkbox" name="remove_emojis" <?=!empty($formRule['remove_emojis'])?'checked':''?>> Remover emojis</label><label><input type="checkbox" name="translation_enabled" <?=!empty($formRule['translation_enabled'])?'checked':''?>> Ativar tradução</label><label><input type="checkbox" name="translation_fallback_original" <?=!empty($formRule['translation_fallback_original'])?'checked':''?>> Preservar original se falhar</label></div>
  </div>
</div>
<section class="treatment-block tmr-smart-format-choice" aria-labelledby="smart-format-title">
  <div class="treatment-title"><span id="smart-format-title">✦ Formatação inteligente com IA</span></div>
  <p class="field-help">Opcional por regra. A interpretação e a tradução inteligentes usam o mesmo provedor definido na regra e, quando necessário, seu fallback configurado. Para gerar cards, o provedor deve aceitar interpretação por IA (Gemini ou Workers AI); Azure Translator e Google Cloud Translation, isoladamente, não interpretam comprovantes. Com a opção desligada, o encaminhamento atual permanece igual.</p>
  <div class="treatment-checks">
    <label><input type="checkbox" name="smart_format_enabled" <?=\App\SmartFormatting::settings((int)($editRule['id']??0))['enabled']?'checked':''?>> Ativar somente nesta regra</label>
  </div>
  <label>Formato de envio
    <select name="smart_output_mode">
      <?php $smartMode=\App\SmartFormatting::settings((int)($editRule['id']??0))['output_mode']; ?>
      <option value="card" <?=$smartMode==='card'?'selected':''?>>Card visual APOSTA VIP (imagem gerada)</option>
      <option value="caption" <?=$smartMode==='caption'?'selected':''?>>Imagem original + legenda formatada</option>
      <option value="text" <?=$smartMode==='text'?'selected':''?>>Somente texto formatado</option>
    </select>
  </label>
  <p class="field-help">A análise original será preservada. A tradução seguirá a configuração desta regra. Se todas as tentativas de IA ou a renderização do card falharem, a mensagem original será preservada e encaminhada; o histórico e os logs registrarão a ordem, o motivo e o tempo das tentativas sem expor o conteúdo da tip.</p>
</section><input type="hidden" name="translation_source_language" value="auto"><div class="saas-actions"><span class="form-note">A regra será salva com segurança no banco de dados.</span><button class="saas-primary"><?= $editRule?'Aplicar alterações →':'Salvar e ativar regra →' ?></button></div></form></section>
<section class="saas-card routing-list rules-panel">
  <div class="saas-card-head rules-panel-head"><div><span class="saas-kicker">REGRAS CONFIGURADAS</span><h2>Fluxos em operação</h2><p><?=sh($rulesTotal)?> <?=($rulesTotal===1?'regra configurada':'regras configuradas')?> neste ambiente.</p></div><div class="rules-panel-meta"><span class="rules-count"><b><?=sh($stats['active'])?></b> ativas</span><span class="rules-page-label">Página <?=sh($rulesPage)?></span></div></div>
  <?php if($rulesTotal===0):?><div class="saas-empty rules-empty"><span class="rules-empty-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v10a2 2 0 0 0 2 2h4M18 17V7a2 2 0 0 0-2-2h-4m-2 12 2 2-2 2m4-18-2 2 2 2"/></svg></span><h3>Nenhuma regra configurada</h3><p>Crie uma regra para começar a encaminhar mensagens automaticamente.</p><a class="saas-primary" href="#nova-regra">+ Criar primeira regra</a></div><?php endif;?>
  <?php foreach($rules as $r):?><article class="routing-rule rule-card <?=((int)$r['enabled']?'':'paused')?>">
    <div class="rule-card-top"><div class="routing-rule-status"><span class="saas-dot <?=((int)$r['enabled']?'':'off')?>"></span><?=((int)$r['enabled']?'ATIVA':'PAUSADA')?></div><span class="rule-id">REGRA #<?=sh($r['id'])?></span></div>
    <div class="routing-path rule-route"><div><small>ORIGEM</small><b title="<?=sh($r['source_chat'])?>"><?=sh($r['source_chat'])?></b></div><span class="rule-route-arrow">→</span><div><small>GATILHO</small><b class="trigger-chip" title="<?=sh($r['trigger_text'])?>"><?=sh($r['trigger_text'])?></b></div><span class="rule-route-arrow">→</span><div><small>DESTINO</small><b title="<?=sh($r['destination_chat'])?>"><?=sh($r['destination_chat'])?></b></div></div>
    <div class="rule-card-bottom"><div class="rule-tags"><span><?=sh(match($r['media_mode']){'previous_photo'=>'Foto anterior','current_media'=>'Mídia atual','text_only'=>'Somente texto',default=>$r['media_mode']})?></span><span><?=((int)$r['remove_links']?'Links removidos':'Links preservados')?></span><span><?=((int)$r['remove_emojis']?'Emojis removidos':'Emojis preservados')?></span><?=trim((string)($r['custom_removals']??''))!==''?'<span>Remoções personalizadas</span>':''?></div><div class="rule-actions"><a class="saas-secondary rule-edit" href="/?page=rules&amp;edit=<?=sh($r['id'])?>"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 5 4 4M4 20l5-1L21 7a2.8 2.8 0 0 0-4-4L5 15ZM14 20h7"/></svg>Editar</a><form method="post" class="routing-delete"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="saas-danger" title="Excluir regra"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></svg>Excluir</button></form></div></div>
  </article><?php endforeach;?><?=paginas($rulesPage,$rulesTotal,'rules_page',6)?></section></div><?php elseif($page==='events'):?><div class="saas-heading"><div><span class="saas-kicker">CENTRAL DE ACOMPANHAMENTO</span><h1>Atividades</h1><p>Veja o que o trabalhador recebeu, processou e encaminhou.</p></div><div class="saas-heading-actions"><a class="saas-secondary" href="/?page=dashboard">← Visão geral</a><a class="saas-primary" href="/?page=rules">Gerenciar regras →</a></div></div>
<section class="activity-summary"><div><span class="activity-summary-label">EVENTOS REGISTRADOS</span><strong><?=sh($eventsTotal)?></strong><small>20 por página</small></div><div><span class="activity-summary-label">ENCAMINHADAS</span><strong class="activity-good"><?=sh($stats['forwarded']??0)?></strong><small>entregas concluídas</small></div><div><span class="activity-summary-label">COM FALHA</span><strong class="activity-bad"><?=sh($stats['failed']??0)?></strong><small>verifique os detalhes</small></div><div><span class="activity-summary-label">IGNORADAS</span><strong class="activity-warn"><?=sh($stats['skipped']??0)?></strong><small>sem encaminhamento</small></div></section>
<section class="saas-card activity-card"><div class="saas-card-head activity-card-head"><div><span class="saas-kicker">REGISTRO OPERACIONAL</span><h2>Histórico de processamento</h2><p>Cada linha representa uma tentativa única de processamento.</p></div><div class="activity-legend"><span><i class="legend-good"></i>Concluída</span><span><i class="legend-bad"></i>Falhou</span><span><i class="legend-warn"></i>Ignorada</span></div></div><?php if(!$events):?><div class="activity-empty"><span>◷</span><h3>Nenhuma atividade registrada</h3><p>O trabalhador continuará conectado e mostrará aqui a próxima mensagem processada.</p></div><?php else:?><div class="activity-list"><?php foreach($events as $e):?><article class="activity-item"><div class="activity-status-dot <?=sh(match($e['status']){'forwarded'=>'Encaminhada','failed'=>'Falhou','skipped'=>'Ignorada','processing'=>'Processando',default=>'Registrada'})?>"></div><div class="activity-main"><div class="activity-item-top"><b><?=sh(match($e['status']){'forwarded'=>'Mensagem encaminhada','failed'=>'Falha no encaminhamento','skipped'=>'Mensagem ignorada','processing'=>'Processando mensagem',default=>'Evento registrado'})?></b><time><?=sh(dataHoraBrasil($e['created_at']))?></time></div><div class="activity-route"><span><?=sh($e['source_chat'])?></span><strong>#<?=sh($e['message_id'])?></strong><span class="route-arrow">→</span><span><?=sh($e['destination_chat'])?></span></div><div class="activity-details"><span>Gatilho: <b><?=sh($e['trigger_text'])?></b></span><span class="activity-badge <?=sh(match($e['status']){'forwarded'=>'Encaminhada','failed'=>'Falhou','skipped'=>'Ignorada','processing'=>'Processando',default=>'Registrada'})?>"><?=sh(match($e['status']){'forwarded'=>'Concluída','failed'=>'Falhou','skipped'=>'Ignorada','processing'=>'Em processamento',default=>$e['status']})?></span></div><?php if(!empty($e['details'])):?><div class="<?=$e['status']==='failed'?'activity-error':'activity-note'?>"><?php if($e['status']==='failed'):?><b>Detalhes da falha</b><?php endif;?><span><?=sh($e['details'])?></span></div><?php endif;?></div></article><?php endforeach;?></div><?php endif;?><?=paginas($eventsPage,$eventsTotal,'events_page')?></section><?php elseif($page==='integrations'):
$googleCloudKey=Repository::integration('google_cloud_api_key');
$googleCloudStats=Repository::translationProviderStats('google_cloud');
$googleCloudTest=Repository::providerTestStatus('google_cloud');
$azureKey=Repository::integration('azure_translator_api_key');
$azureRegion=Repository::integration('azure_translator_region');
$azureEndpoint=Repository::integration('azure_translator_endpoint')?:'https://api.cognitive.microsofttranslator.com';
$geminiKey=Repository::integration('gemini_api_key');
$geminiModel=Repository::integration('gemini_model')?:'gemini-3.6-flash';
$workersAIKey=\App\WorkersAITranslation::token();
$workersAIAccount=\App\WorkersAITranslation::account();
$primaryProvider=Repository::translationPrimaryProvider();
$fallbackProvider=Repository::translationFallbackProvider();
$azureStats=Repository::translationProviderStats('azure'); $geminiStats=Repository::translationProviderStats('gemini');
$azureTest=Repository::providerTestStatus('azure'); $geminiTest=Repository::providerTestStatus('gemini');
?><div class="saas-heading"><div><span class="saas-kicker">SERVIÇOS EXTERNOS</span><h1>Integrações</h1><p>Gerencie suas conexões, configure os tradutores e acompanhe o desempenho de cada serviço.</p></div><div class="saas-heading-actions"><a class="saas-secondary" href="/?page=dashboard">← Visão geral</a></div></div>

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
<section class="saas-card integrations-connection"><div><span class="saas-kicker">CONEXÃO</span><h2>Telegram</h2><p>Gerencie a conta usada para receber e encaminhar as mensagens.</p></div><a class="saas-secondary" href="/connect.php">Gerenciar conexão →</a></section>
<section class="translation-routing-card saas-card"><div class="saas-card-head"><div><span class="saas-kicker">ORQUESTRAÇÃO</span><h2>Prioridade e fallback</h2><p>A regra sempre tem prioridade. O fallback usa o provedor alternativo configurado quando estiver habilitado.</p></div></div><form method="post" class="translation-routing-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="action" value="save_translation_routing"><label>Provedor principal<select id="translation-primary" name="translation_primary_provider"><option value="azure" <?=$primaryProvider==='azure'?'selected':''?>>Microsoft Azure Translator</option><option value="gemini" <?=$primaryProvider==='gemini'?'selected':''?>>Google Gemini</option><option value="google_cloud" <?=$primaryProvider==='google_cloud'?'selected':''?>>Google Cloud Translation</option><option value="workers_ai" <?=($primaryProvider==='workers_ai')?'selected':''?>>Cloudflare Workers AI</option></select></label><label>Provedor de fallback<select id="translation-fallback" name="translation_fallback_provider"><option value="none" <?=$fallbackProvider==='none'?'selected':''?>>Nenhum</option><option value="azure" <?=$fallbackProvider==='azure'?'selected':''?>>Microsoft Azure Translator</option><option value="gemini" <?=$fallbackProvider==='gemini'?'selected':''?>>Google Gemini</option><option value="google_cloud" <?=$fallbackProvider==='google_cloud'?'selected':''?>>Google Cloud Translation</option><option value="workers_ai" <?=($fallbackProvider==='workers_ai')?'selected':''?>>Cloudflare Workers AI</option></select></label><button class="saas-primary">Salvar prioridade →</button></form></section>
<div class="integrations-section-heading"><div><span class="saas-kicker">PROVEDORES DE TRADUÇÃO</span><h2>Credenciais e conexão</h2><p>Clique no card para expandir. Cada provedor mantém sua própria chave, teste de conexão, data/hora, latência e diagnóstico.</p></div></div><div class="translation-provider-grid">
<?php require __DIR__.'/app/google-cloud-card.php'; ?>
<section class="saas-card translation-provider-card"><div class="saas-card-head"><div><span class="saas-kicker">MICROSOFT AZURE</span><h2>Microsoft Azure Translator</h2><p>API dedicada de tradução de texto, otimizada para baixa latência.</p></div><span class="form-status <?=$azureKey?'is-configured':'is-empty'?>"><i></i><?=$azureKey?'Configurado':'Não configurado'?></span></div>
<form method="post" class="provider-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="provider" value="azure"><div class="saas-form-grid"><label class="field-wide">API Key<span class="field-help"><?=$azureKey?'Atual: '.sh(TranslationService::maskSecret($azureKey)).'. Digite uma nova chave apenas para substituir.':'Informe a chave do recurso Translator.'?></span><input name="azure_api_key" type="password" autocomplete="new-password" placeholder="<?=$azureKey?'••••••••••••••••':'Cole a API Key do Azure'?>"></label><label>Region<span class="field-help">Obrigatória para recursos regionais/multisserviço.</span><input name="azure_region" value="<?=sh($azureRegion)?>" placeholder="Ex.: brazilsouth"></label><label>Endpoint<span class="field-help">Use o endpoint oficial ou personalizado do recurso.</span><input name="azure_endpoint" value="<?=sh($azureEndpoint)?>" placeholder="https://api.cognitive.microsofttranslator.com"></label></div><div class="provider-actions"><button class="saas-primary" name="action" value="save_translation_provider">Salvar</button><button class="saas-secondary" name="action" value="test_translation_provider">Testar conexão</button></div></form>
<div class="provider-test <?=$azureTest?((int)$azureTest['last_test_ok']?'ok':'bad'):'neutral'?>"><b>Último teste</b><span><?=$azureTest?(int)$azureTest['last_test_ok']?'Conexão válida':'Falha':'Ainda não testado'?></span><small><?=$azureTest?sh(dataHoraBrasil($azureTest['last_test_at'])).' · '.(int)$azureTest['last_test_latency_ms'].' ms':'—'?></small><?php if($azureTest&&!$azureTest['last_test_ok']&&!empty($azureTest['last_test_error'])):?><em><?=sh($azureTest['last_test_error'])?></em><?php endif;?></div>
<div class="provider-stats"><div><span>Traduções</span><b><?=sh($azureStats['total'])?></b></div><div><span>Sucessos</span><b><?=sh($azureStats['successful'])?></b></div><div><span>Falhas</span><b><?=sh($azureStats['failures'])?></b></div><div><span>Latência média</span><b><?=sh($azureStats['avg_latency'])?> ms</b></div><div><span>Última latência</span><b><?=sh($azureStats['last_latency'])?> ms</b></div><div><span>Último uso</span><b><?=sh(dataHoraBrasil($azureStats['last_use']))?></b></div></div></section>

<section class="saas-card translation-provider-card"><div class="saas-card-head"><div><span class="saas-kicker">GOOGLE AI</span><h2>Google Gemini</h2><p>Tradução com instrução curta e determinística, sem respostas adicionais.</p></div><span class="form-status <?=$geminiKey?'is-configured':'is-empty'?>"><i></i><?=$geminiKey?'Configurado':'Não configurado'?></span></div>
<form method="post" class="provider-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="provider" value="gemini"><div class="saas-form-grid"><label class="field-wide">API Key<span class="field-help"><?=$geminiKey?'Atual: '.sh(TranslationService::maskSecret($geminiKey)).'. Digite uma nova chave apenas para substituir.':'Informe a chave do Google AI Studio.'?></span><input name="gemini_api_key" type="password" autocomplete="new-password" placeholder="<?=$geminiKey?'••••••••••••••••':'Cole a API Key do Gemini'?>"></label><label class="field-wide">Modelo<span class="field-help">Modelo utilizado nas traduções.</span><input name="gemini_model" value="<?=sh($geminiModel)?>" placeholder="gemini-3.6-flash"></label></div><div class="provider-actions"><button class="saas-primary" name="action" value="save_translation_provider">Salvar</button><button class="saas-secondary" name="action" value="test_translation_provider">Testar conexão</button></div></form>
<div class="provider-test <?=$geminiTest?((int)$geminiTest['last_test_ok']?'ok':'bad'):'neutral'?>"><b>Último teste</b><span><?=$geminiTest?(int)$geminiTest['last_test_ok']?'Conexão válida':'Falha':'Ainda não testado'?></span><small><?=$geminiTest?sh(dataHoraBrasil($geminiTest['last_test_at'])).' · '.(int)$geminiTest['last_test_latency_ms'].' ms':'—'?></small><?php if($geminiTest&&!$geminiTest['last_test_ok']&&!empty($geminiTest['last_test_error'])):?><em><?=sh($geminiTest['last_test_error'])?></em><?php endif;?></div>
<div class="provider-stats"><div><span>Traduções</span><b><?=sh($geminiStats['total'])?></b></div><div><span>Sucessos</span><b><?=sh($geminiStats['successful'])?></b></div><div><span>Falhas</span><b><?=sh($geminiStats['failures'])?></b></div><div><span>Latência média</span><b><?=sh($geminiStats['avg_latency'])?> ms</b></div><div><span>Última latência</span><b><?=sh($geminiStats['last_latency'])?> ms</b></div><div><span>Último uso</span><b><?=sh(dataHoraBrasil($geminiStats['last_use']))?></b></div></div></section>
<?php require __DIR__.'/app/workers-ai-card.php'; ?>
</div>
<script>document.addEventListener('DOMContentLoaded',()=>{const p=document.getElementById('translation-primary'),f=document.getElementById('translation-fallback');if(!p||!f)return;const sync=()=>{for(const o of f.options)o.disabled=o.value!=='none'&&o.value===p.value;if(f.value===p.value)f.value='none';};p.addEventListener('change',sync);sync();});</script>
<?php else:?><div class="saas-heading"><div><span class="saas-kicker">CENTRAL DE OPERAÇÃO</span><h1>Visão geral</h1><p>Acompanhe a conexão, as regras e cada tentativa de encaminhamento.</p></div><div class="saas-heading-actions"><a class="saas-secondary" href="/?page=events">Ver atividade</a><a class="saas-primary" href="/?page=rules">+ Nova regra</a></div></div>
<section class="overview-status <?=($status['status']??'desconectado')==='conectado'?'is-good':'is-alert'?>"><div class="overview-status-icon"><i></i></div><div><span class="saas-kicker">ESTADO DO SERVIÇO</span><h2><?=sh(match($status['status']??'desconectado'){'conectado'=>'Conectado','processando'=>'Processando','reconectando'=>'Reconectando','erro'=>'Erro','desconectado'=>'Desconectado',default=>'Estado não identificado'})?></h2><p><?=($status['status']??'')==='conectado'?'A conexão está ativa e o trabalhador está pronto para receber novas mensagens.':'O trabalhador não está em condição normal de processamento. Consulte os detalhes abaixo.'?></p></div><div class="overview-status-meta"><span>Última verificação</span><b><?=sh(dataHoraBrasil($status['last_activity_at']??null))?></b><a href="/connect.php">Gerenciar conexão →</a></div></section>
<?php if(($status['status']??'')==='erro' && !empty($status['last_error'])):?><section class="overview-alert"><b>Falha registrada no trabalhador</b><p><?=sh($status['last_error'])?></p><small>A conexão permanece monitorada. Verifique a conta e os canais configurados antes de tentar novamente.</small></section><?php endif;?>
<section class="saas-metrics overview-metrics"><div class="saas-metric"><div class="saas-metric-label">REGRAS ATIVAS <span class="saas-metric-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v10a2 2 0 0 0 2 2h4M18 17V7a2 2 0 0 0-2-2h-4m-2 12 2 2-2 2m4-18-2 2 2 2"/></svg></span></div><strong><?=sh($stats['active'])?></strong><small><?=sh($stats['total'])?> regras cadastradas</small></div><div class="saas-metric"><div class="saas-metric-label">ENCAMINHADAS HOJE <span class="saas-metric-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m21 3-7 18-4-7-7-4Zm0 0L10 14"/></svg></span></div><strong><?=sh($stats['forwarded']??0)?></strong><small>entregas concluídas</small></div><div class="saas-metric"><div class="saas-metric-label">TENTATIVAS COM FALHA <span class="saas-metric-icon danger"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m10 4-8 14a2 2 0 0 0 2 3h16a2 2 0 0 0 2-3L14 4a2 2 0 0 0-4 0ZM12 9v4m0 4h.01"/></svg></span></div><strong class="metric-danger"><?=sh($stats['failed']??0)?></strong><small>exigem atenção</small></div><div class="saas-metric"><div class="saas-metric-label">EVENTOS PROCESSADOS <span class="saas-metric-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span></div><strong><?=sh(($stats['forwarded']??0)+($stats['failed']??0)+($stats['skipped']??0))?></strong><small><?=sh($stats['skipped']??0)?> ignorados pelas regras</small></div></section>
<div class="saas-grid overview-grid"><section class="saas-card"><div class="saas-card-head"><div><span class="saas-kicker">MONITORAMENTO</span><h2>Últimas movimentações</h2><p>O que aconteceu recentemente no roteador.</p></div><a class="saas-link" href="/?page=events">Abrir histórico →</a></div><?php if(!$events):?><div class="saas-empty">Ainda não há mensagens processadas. O trabalhador continuará conectado e aguardando novos eventos.</div><?php else:?><div class="saas-table-wrap"><table class="saas-table"><thead><tr><th>Origem</th><th>Destino</th><th>Resultado</th><th>Quando</th></tr></thead><tbody><?php foreach(array_slice($events,0,6) as $e):?><tr><td><?=sh($e['source_chat'])?> · #<?=sh($e['message_id'])?></td><td><?=sh($e['destination_chat'])?></td><td><span class="saas-badge <?=sh(match($e['status']){'forwarded'=>'Encaminhada','failed'=>'Falhou','skipped'=>'Ignorada','processing'=>'Processando',default=>'Registrada'})?>"><?=sh(match($e['status']){'forwarded'=>'Encaminhada','failed'=>'Falhou','skipped'=>'Ignorada','processing'=>'Processando',default=>$e['status']})?></span></td><td><?=sh(dataHoraBrasil($e['created_at']))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section><section class="saas-card"><div class="saas-card-head"><div><span class="saas-kicker">CONFIGURAÇÃO</span><h2>Regras em operação</h2><p>Resumo das origens e destinos ativos.</p></div><a class="saas-link" href="/?page=rules">Gerenciar →</a></div><?php foreach(array_slice($rules,0,5) as $r):?><div class="saas-rule"><div class="saas-rule-top"><b><span class="saas-dot <?=((int)$r['enabled']?'':'off')?>"></span><?=sh($r['source_chat'])?></b><span class="rule-state"><?=((int)$r['enabled']?'Ativa':'Pausada')?></span></div><p><?=sh($r['trigger_text'])?> <span class="rule-arrow">→</span> <?=sh($r['destination_chat'])?></p></div><?php endforeach;if(!$rules):?><div class="saas-empty">Nenhuma regra criada. Configure a primeira para iniciar os encaminhamentos.</div><?php endif;?></section></div>
<section class="saas-card overview-guide"><div><span class="saas-kicker">COMO FUNCIONA</span><h2>O trabalhador permanece pronto</h2><p>Sem mensagens, o sistema não entra em espera: ele continua conectado, monitorando os canais e preparado para processar o próximo evento.</p></div><div class="guide-points"><span><i>1</i>Recebe a mensagem</span><span><i>2</i>Valida a regra</span><span><i>3</i>Encaminha ao destino</span></div></section><?php endif;?></main></div></div><script src="/assets/live.js" defer></script><script src="/assets/toast.js" defer></script><script src="/assets/brand/integrations-v10.js?v=7" defer></script></body></html>
