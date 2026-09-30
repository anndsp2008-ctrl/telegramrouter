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
            if($provider==='openai'){
                $newKey=trim((string)($_POST['openai_api_key']??''));
                $enabled=isset($_POST['openai_enabled'])?'1':'0';
                $model=trim((string)($_POST['openai_model']??'gpt-5.6-luna'));
                $fallbackEnabled=isset($_POST['openai_fallback_enabled'])?'1':'0';
                $fallbackModel=trim((string)($_POST['openai_fallback_model']??'gpt-5.6-terra'));
                if(!\App\OpenAIProvider::validModel($model)) throw new RuntimeException('Modelo principal OpenAI inválido.');
                if(!\App\OpenAIProvider::validModel($fallbackModel)) throw new RuntimeException('Modelo de fallback OpenAI inválido.');
                if($newKey!=='') Repository::saveIntegration('openai_api_key',$newKey);
                Repository::saveIntegration('openai_enabled',$enabled);
                Repository::saveIntegration('openai_model',$model);
                Repository::saveIntegration('openai_fallback_enabled',$fallbackEnabled);
                Repository::saveIntegration('openai_fallback_model',$fallbackModel);
                $notice='OpenAI atualizada com segurança.';
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
            if($provider==='openai'){
                foreach(['openai_api_key','openai_model'] as $field){$value=trim((string)($_POST[$field]??''));if($value!=='')$overrides[$field]=$value;}
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
            } else throw new RuntimeException('Provedor esportivo inválido.');
            $page='integrations';
        }

        if($action==='test_sports_api_provider'){
            $provider=(string)($_POST['provider']??'');
            $override='';
            if($provider==='stake') $override=trim((string)($_POST['stake_odds_api_key']??''));
            elseif($provider==='api_football') $override=trim((string)($_POST['api_football_key']??''));
            else throw new RuntimeException('Provedor esportivo inválido.');

            $test=\App\SportsApiIntegration::test($provider,$override);
            Repository::saveProviderTest(
                $provider,
                (bool)$test['ok'],
                (int)$test['latency_ms'],
                $test['http_code']!==null?(int)$test['http_code']:null,
                $test['error']!==null?(string)$test['error']:null
            );
            if($test['ok']) $notice=(string)$test['message']; else $error=(string)$test['message'];
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
$credentials=Repository::credentials();$connectedPhone=(string)($credentials['telegram_phone']??'');if($connectedPhone!==''&&$connectedPhone[0]!=='+')$connectedPhone='+'.$connectedPhone;$rulesTotal=Repository::rulesTotal();$rulesPage=min($rulesPage,max(1,(int)ceil($rulesTotal/6)));$rules=Repository::rules($rulesPage,6);$events=Repository::events($eventsPage,20);$eventsTotal=Repository::eventsTotal();$stats=Repository::overview();$status=Repository::workerStatus();$editRule=isset($_GET['edit'])?Repository::rule((int)$_GET['edit']):null;$formRule=$editRule?:['source_chat'=>'','trigger_text'=>'','exclude_text'=>'','destination_chat'=>'','media_mode'=>'previous_photo','translation_provider'=>Repository::translationPrimaryProvider(),'translation_target_language'=>'pt-BR','remove_links'=>1,'remove_emojis'=>1,'custom_removals'=>'','translation_enabled'=>0,'translation_fallback_original'=>1,'enabled'=>1];
function sh(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function paginas(int $page,int $total,string $param,int $perPage=20):string { $pages=max(1,(int)ceil($total/$perPage)); if($pages<=1)return ''; $out='<nav class="pagination" aria-label="Navegação de páginas">'; for($i=1;$i<=$pages;$i++){ $query=$_GET; $query[$param]=$i; $out.='<a class="'.($i===$page?'current':'').'" href="?'.sh(http_build_query($query)).'">'.$i.'</a>'; } return $out.'</nav>'; }
$active=$page==='dashboard'?'dashboard':$page;
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Telegram Router · Workspace</title><script src="/assets/brand/theme.js?v=1"></script><link rel="stylesheet" href="/assets/saas.css"><link rel="stylesheet" href="/assets/brand/navigation-shell.css?v=1"><link rel="stylesheet" href="/assets/translation-v2.css?v=2"><link rel="stylesheet" href="/assets/responsive.css?v=4"><link rel="stylesheet" href="/assets/dialogs.css?v=3">

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
</style><link rel="stylesheet" href="/assets/brand/workers-ai-provider.css?v=5"><link rel="stylesheet" href="/assets/brand/brand.css?v=2"><link rel="stylesheet" href="/assets/brand/mobile-shell.css?v=2"><link rel="stylesheet" href="/assets/brand/integrations-v10.css?v=9&workers-dot=5&openai-form=2&sports-api=1"><link rel="stylesheet" href="/assets/brand/activity-status-badges.css?v=5"><link rel="stylesheet" href="/assets/brand/horizontal-scrollbar.css?v=1"><link rel="stylesheet" href="/assets/brand/project-scrollbar.css?v=1"><link rel="stylesheet" href="/assets/brand/mobile-visual-audit.css?v=17"><link rel="stylesheet" href="/assets/brand/service-status-responsive.css?v=1"><link rel="stylesheet" href="/assets/brand/connect-responsive.css?v=2"><link rel="stylesheet" href="/assets/brand/orchestration-responsive.css?v=1"><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3"><link rel="manifest" href="/manifest.webmanifest?v=2"><meta name="theme-color" content="#0B1220"><link rel="stylesheet" href="/assets/brand/mobile-bottom-navigation.css?v=2">
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
</style><link rel="stylesheet" href="/assets/brand/mobile-app-header.css?v=2"><link rel="stylesheet" href="/assets/brand/logout-icon.css?v=1"><link rel="stylesheet" href="/assets/brand/smart-format.css?v=1"><link rel="stylesheet" href="/assets/brand/smart-format.css?v=1"><link rel="stylesheet" href="/assets/brand/theme.css?v=7"></head><body><div class="saas-shell"><?php $navActive=(string)($active ?? 'dashboard'); require __DIR__.'/app/project-sidebar.php'; ?><div class="saas-main"><header class="saas-topbar"><div class="tmr-app-header-brand" aria-label="TelegramRouter">
  <span class="tmr-app-brand-symbol"><img src="/assets/brand/mark.svg?v=1" alt=""></span>
  <span class="tmr-app-brand-name"><b>Telegram<span>Router</span></b><small>Conecte. Direcione. Automatize.</small></span>
</div><div class="saas-user"><span class="saas-avatar">AN</span><span><?=sh($saudacaoNome)?></span><?php if($connectedPhone!==''): ?><span class="connected-phone">Telegram: <?=sh($connectedPhone)?></span><?php endif; ?><?php require __DIR__.'/app/theme-toggle.php'; ?><form method="post"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="action" value="logout"><button class="saas-logout" type="submit" aria-label="Sair" title="Sair"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M14 16l4-4-4-4"/><path d="M18 12H8"/></svg></button></form></div></header><?php $navActive=(string)($active ?? 'dashboard'); require __DIR__.'/app/project-mobile-nav.php'; ?><main class="saas-content"><?php if($error):?><div class="saas-flash error"><?=sh($error)?></div><?php endif;?><?php if($notice):?><div class="saas-flash success"><?=sh($notice)?></div><?php endif;?><?php if($page==='rules'):?><div class="saas-heading"><div><span class="saas-kicker">AUTOMAÇÃO</span><h1>Regras de roteamento</h1><p>Defina exatamente quando uma mensagem deve ser tratada e para onde será enviada.</p></div><div class="saas-heading-actions"><a class="saas-secondary" href="/?page=dashboard">← Visão geral</a><a class="saas-primary" href="#nova-regra">+ Criar regra</a></div></div>
<section class="routing-flow"><div><span class="flow-number">01</span><b>Origem</b><small>Onde a mensagem chega</small></div><span class="flow-arrow">→</span><div><span class="flow-number">02</span><b>Gatilho</b><small>O que ativa a regra</small></div><span class="flow-arrow">→</span><div><span class="flow-number">03</span><b>Destino</b><small>Para onde enviar</small></div></section>
<div class="rules-columns"><section class="saas-card saas-form-card routing-form" id="nova-regra"><div class="saas-card-head"><div><span class="saas-kicker"><?= $editRule?'EDIÇÃO DE REGRA':'NOVA CONFIGURAÇÃO' ?></span><h2><?= $editRule?'Atualizar regra de roteamento':'Quando esta regra for acionada' ?></h2><p>Os campos abaixo serão avaliados pelo trabalhador em tempo real.</p></div><span class="form-status"><i></i>Pronta para ativar</span></div><form method="post"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="action" value="<?=$editRule?'update_rule':'save_rule'?>"><?php if($editRule):?><input type="hidden" name="id" value="<?=sh($editRule['id'])?>"><?php endif;?><div class="form-section-title">01 · Identificação dos canais</div><div class="saas-form-grid"><label>Canal ou grupo de origem<span class="field-help">Aceita ID numérico, @username ou nome público.</span><input name="source_chat" value="<?=sh($formRule['source_chat'])?>" placeholder="@canal_origem ou -100…" required></label><label>Canal ou grupo de destino<span class="field-help">A conta Telegram precisa ter acesso ao destino.</span><input name="destination_chat" value="<?=sh($formRule['destination_chat'])?>" placeholder="@meu_canal ou -100…" required></label></div><div class="form-section-title">02 · Condição de acionamento</div><div class="saas-form-grid"><label class="field-wide">Texto que deve aparecer na mensagem<span class="field-help">A comparação não diferencia letras maiúsculas e minúsculas.</span><input name="trigger_text" value="<?=sh($formRule['trigger_text'])?>" placeholder="Ex.: CUPOM, OFERTA, SINAL…" required></label><label class="field-wide">Não encaminhar se contiver<span class="field-help">Uma condição por linha. Linhas funcionam como OU; use + para exigir todos os termos da mesma linha. Não diferencia maiúsculas e minúsculas.</span><textarea name="exclude_text" rows="4" placeholder="Ex.: CASHOUT&#10;RESULTADO FINAL&#10;APOSTA + FINALIZADA"><?=sh($formRule['exclude_text']??'')?></textarea></label><label class="activation-field">Estado inicial<span class="field-help">Você pode pausar a regra depois na configuração.</span><span class="activation-toggle"><input type="checkbox" name="enabled" checked> Ativar imediatamente</span></label></div><div class="form-section-title">03 · Tratamento do conteúdo</div>
<div class="content-treatment">
  <div class="treatment-block treatment-media">
    <div class="treatment-block-head"><span class="treatment-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1"/><path d="m3 17 5-5 4 4 4-6 5 7"/></svg></span><div><b>Formato enviado</b><small>Escolha o conteúdo que chegará ao destino.</small></div></div>
    <label>Conteúdo enviado<select name="media_mode"><option value="previous_photo" <?=$formRule['media_mode']==='previous_photo'?'selected':''?>>Imagem Anterior + Legenda</option><option value="current_media" <?=$formRule['media_mode']==='current_media'?'selected':''?>>Mídia da mensagem + legenda</option><option value="text_only" <?=$formRule['media_mode']==='text_only'?'selected':''?>>Somente texto</option></select></label>
  </div>
  <div class="treatment-block treatment-translation">
    <div class="treatment-block-head"><span class="treatment-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 5h12M9 3v2m4 0c-1 6-4 9-9 11m1-9c1 4 4 7 8 9m1 5 4-11 4 11m-6-4h4"/></svg></span><div><b>Tradução</b><small>Converta a mensagem antes do envio.</small></div></div>
    <label>Provedor de tradução<select name="translation_provider"><option value="openai" <?=$formRule['translation_provider']==='openai'?'selected':''?>>OpenAI</option><option value="gemini" <?=$formRule['translation_provider']==='gemini'?'selected':''?>>Google Gemini</option><option value="google_cloud" <?=$formRule['translation_provider']==='google_cloud'?'selected':''?>>Google Cloud Translation</option><option value="workers_ai" <?=($formRule['translation_provider']==='workers_ai')?'selected':''?>>Cloudflare Workers AI</option></select></label>
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
  <p class="field-help">Opcional por regra. A interpretação e a tradução inteligentes usam o mesmo provedor definido na regra e, quando necessário, seu fallback configurado. Para gerar cards, o provedor deve aceitar interpretação por IA (OpenAI, Gemini ou Workers AI); Google Cloud Translation, isoladamente, não interpreta comprovantes. Com a opção desligada, o encaminhamento atual permanece igual.</p>
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
    <div class="rule-card-bottom"><div class="rule-tags"><span><?=sh(match($r['media_mode']){'previous_photo'=>'Foto anterior','current_media'=>'Mídia atual','text_only'=>'Somente texto',default=>$r['media_mode']})?></span><span><?=((int)$r['remove_links']?'Links removidos':'Links preservados')?></span><span><?=((int)$r['remove_emojis']?'Emojis removidos':'Emojis preservados')?></span><?=trim((string)($r['exclude_text']??''))!==''?'<span>Exclusão configurada</span>':''?><?=trim((string)($r['custom_removals']??''))!==''?'<span>Remoções personalizadas</span>':''?></div><div class="rule-actions"><a class="saas-secondary rule-edit" href="/?page=rules&amp;edit=<?=sh($r['id'])?>"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 5 4 4M4 20l5-1L21 7a2.8 2.8 0 0 0-4-4L5 15ZM14 20h7"/></svg>Editar</a><form method="post" class="routing-delete" data-dialog-confirm data-dialog-tone="danger"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="saas-danger" title="Excluir regra"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></svg>Excluir</button></form></div></div>
  </article><?php endforeach;?><?=paginas($rulesPage,$rulesTotal,'rules_page',6)?></section></div><?php elseif($page==='events'):?><div class="saas-heading"><div><span class="saas-kicker">CENTRAL DE ACOMPANHAMENTO</span><h1>Atividades</h1><p>Veja o que o trabalhador recebeu, processou e encaminhou.</p></div><div class="saas-heading-actions"><a class="saas-secondary" href="/?page=dashboard">← Visão geral</a><a class="saas-primary" href="/?page=rules">Gerenciar regras →</a></div></div>
<section class="activity-summary"><div><span class="activity-summary-label">EVENTOS REGISTRADOS</span><strong><?=sh($eventsTotal)?></strong><small>20 por página</small></div><div><span class="activity-summary-label">ENCAMINHADAS</span><strong class="activity-good"><?=sh($stats['forwarded']??0)?></strong><small>entregas concluídas</small></div><div><span class="activity-summary-label">COM FALHA</span><strong class="activity-bad"><?=sh($stats['failed']??0)?></strong><small>verifique os detalhes</small></div><div><span class="activity-summary-label">IGNORADAS</span><strong class="activity-warn"><?=sh($stats['skipped']??0)?></strong><small>sem encaminhamento</small></div></section>
<section class="saas-card activity-card"><div class="saas-card-head activity-card-head"><div><span class="saas-kicker">REGISTRO OPERACIONAL</span><h2>Histórico de processamento</h2><p>Cada linha representa uma tentativa única de processamento.</p></div><div class="activity-legend"><span><i class="legend-good"></i>Concluída</span><span><i class="legend-bad"></i>Falhou</span><span><i class="legend-warn"></i>Ignorada</span></div></div><?php if(!$events):?><div class="activity-empty"><span>◷</span><h3>Nenhuma atividade registrada</h3><p>O trabalhador continuará conectado e mostrará aqui a próxima mensagem processada.</p></div><?php else:?><div class="activity-list"><?php foreach($events as $e):?><article class="activity-item"><div class="activity-status-dot <?=sh(match($e['status']){'forwarded'=>'Encaminhada','failed'=>'Falhou','skipped'=>'Ignorada','processing'=>'Processando',default=>'Registrada'})?>"></div><div class="activity-main"><div class="activity-item-top"><b><?=sh(match($e['status']){'forwarded'=>'Mensagem encaminhada','failed'=>'Falha no encaminhamento','skipped'=>'Mensagem ignorada','processing'=>'Processando mensagem',default=>'Evento registrado'})?></b><time><?=sh(dataHoraBrasil($e['created_at']))?></time></div><div class="activity-route"><span><?=sh($e['source_chat'])?></span><strong>#<?=sh($e['message_id'])?></strong><span class="route-arrow">→</span><span><?=sh($e['destination_chat'])?></span></div><div class="activity-details"><span>Gatilho: <b><?=sh($e['trigger_text'])?></b></span><span class="activity-badge <?=sh(match($e['status']){'forwarded'=>'Encaminhada','failed'=>'Falhou','skipped'=>'Ignorada','processing'=>'Processando',default=>'Registrada'})?>"><?=sh(match($e['status']){'forwarded'=>'Concluída','failed'=>'Falhou','skipped'=>'Ignorada','processing'=>'Em processamento',default=>$e['status']})?></span></div><?php if(!empty($e['details'])):?><div class="<?=$e['status']==='failed'?'activity-error':'activity-note'?>"><?php if($e['status']==='failed'):?><b>Detalhes da falha</b><?php endif;?><span><?=sh($e['details'])?></span></div><?php endif;?></div></article><?php endforeach;?></div><?php endif;?><?=paginas($eventsPage,$eventsTotal,'events_page')?></section><?php elseif($page==='integrations'):
$googleCloudKey=Repository::integration('google_cloud_api_key');
$googleCloudStats=Repository::translationProviderStats('google_cloud');
$googleCloudTest=Repository::providerTestStatus('google_cloud');
$openaiKey=\App\OpenAIProvider::apiKey();
$openaiEnabled=\App\OpenAIProvider::enabled();
$openaiModel=\App\OpenAIProvider::primaryModel();
$openaiFallbackEnabled=\App\OpenAIProvider::fallbackEnabled();
$openaiFallbackModel=\App\OpenAIProvider::fallbackModel();
$geminiKey=Repository::integration('gemini_api_key');
$geminiModel=Repository::integration('gemini_model')?:'gemini-3.6-flash';
$workersAIKey=\App\WorkersAITranslation::token();
$workersAIAccount=\App\WorkersAITranslation::account();
$stakeKey=\App\SportsApiIntegration::stakeKey();
$apiFootballKey=\App\SportsApiIntegration::apiFootballKey();
$stakeTest=Repository::providerTestStatus('stake');
$apiFootballTest=Repository::providerTestStatus('api_football');
$primaryProvider=Repository::translationPrimaryProvider();
$fallbackProvider=Repository::translationFallbackProvider();
$openaiStats=Repository::translationProviderStats('openai'); $geminiStats=Repository::translationProviderStats('gemini');
$openaiTest=Repository::providerTestStatus('openai'); $geminiTest=Repository::providerTestStatus('gemini');
?><div class="saas-heading"><div><span class="saas-kicker">SERVIÇOS EXTERNOS</span><h1>Integrações</h1><p>Gerencie suas conexões, configure os tradutores e acompanhe o desempenho de cada serviço.</p></div><div class="saas-heading-actions"><a class="saas-secondary" href="/?page=dashboard">← Visão geral</a></div></div>

<!-- integrations_ui_v10 -->
<section class="integrations-overview-v10">
  <div class="integrations-overview-v10-copy">
    <span>CENTRAL DE INTEGRAÇÕES</span>
    <strong>Serviços externos do Telegram Router</strong>
    <small>Configure credenciais, valide conexões e acompanhe o estado de cada provedor sem poluição visual.</small>
  </div>
  <div class="integrations-overview-v10-status">
    <?php $integrationKeyCount=(!empty($openaiKey)?1:0)+(!empty($geminiKey)?1:0)+(!empty($googleCloudKey)?1:0)+((!empty($workersAIKey)&&!empty($workersAIAccount))?1:0)+(!empty($stakeKey)?1:0)+(!empty($apiFootballKey)?1:0); ?>
    <span class="<?=$integrationKeyCount>0?'is-good':''?>">Chaves <?=$integrationKeyCount?>/6</span>
    <span class="<?=$connectedPhone!==''?'is-good':''?>">Telegram <?=$connectedPhone!==''?'online':'offline'?></span>
  </div>
</section>
<section class="saas-card integrations-connection"><div><span class="saas-kicker">CONEXÃO</span><h2>Telegram</h2><p>Gerencie a conta usada para receber e encaminhar as mensagens.</p></div><a class="saas-secondary" href="/connect.php">Gerenciar conexão →</a></section>
<div class="integrations-section-heading sports-api-heading"><div><span class="saas-kicker">APIS ESPORTIVAS</span><h2>Validação e resultados</h2><p>Substitua as credenciais usadas para validar odds na Stake e consultar partidas/resultados na API-Football. O teste pode ser executado antes de salvar.</p></div></div>
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
    <div><span class="saas-kicker">API-SPORTS</span><h2>API-Football</h2><p>Consulta oficial de partidas, horários, placares e estatísticas usadas pelo módulo de resultados.</p></div>
    <span class="provider-badge <?=$apiFootballKey?'is-configured':'is-empty'?>"><?=$apiFootballKey?'Configurado':'Não configurado'?></span>
  </div>
  <form method="post" class="provider-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="provider" value="api_football"><div class="saas-form-grid">
    <label class="field-wide">API Key<span class="field-help"><?=$apiFootballKey?'Atual: '.sh(TranslationService::maskSecret($apiFootballKey)).'. Digite uma nova chave apenas para substituir.':'Informe a chave da API-Football.'?></span><input name="api_football_key" type="password" autocomplete="new-password" placeholder="<?=$apiFootballKey?'••••••••••••••••':'Cole a API Key da API-Football'?>"></label>
  </div><div class="provider-actions"><button class="saas-primary" name="action" value="save_sports_api_provider">Salvar</button><button class="saas-secondary" name="action" value="test_sports_api_provider">Testar conexão</button></div></form>
  <div class="provider-test <?=$apiFootballTest?((int)$apiFootballTest['last_test_ok']?'ok':'bad'):'neutral'?>"><b>Último teste</b><span><?=$apiFootballTest?((int)$apiFootballTest['last_test_ok']?'Conexão válida':'Falha'.(!empty($apiFootballTest['last_test_error'])?' — '.sh((string)$apiFootballTest['last_test_error']):(!empty($apiFootballTest['last_test_http_code'])?' — HTTP '.(int)$apiFootballTest['last_test_http_code']:''))):'Ainda não testado'?></span><small><?=$apiFootballTest?sh(dataHoraBrasil($apiFootballTest['last_test_at'])).' · '.(int)$apiFootballTest['last_test_latency_ms'].' ms':'—'?></small></div>
</section>
</div>
<section class="translation-routing-card saas-card"><div class="saas-card-head"><div><span class="saas-kicker">ORQUESTRAÇÃO</span><h2>Prioridade e fallback</h2><p>A regra sempre tem prioridade. O fallback usa o provedor alternativo configurado quando estiver habilitado.</p></div></div><form method="post" class="translation-routing-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="action" value="save_translation_routing"><label>Provedor principal<select id="translation-primary" name="translation_primary_provider"><option value="openai" <?=$primaryProvider==='openai'?'selected':''?>>OpenAI</option><option value="gemini" <?=$primaryProvider==='gemini'?'selected':''?>>Google Gemini</option><option value="google_cloud" <?=$primaryProvider==='google_cloud'?'selected':''?>>Google Cloud Translation</option><option value="workers_ai" <?=($primaryProvider==='workers_ai')?'selected':''?>>Cloudflare Workers AI</option></select></label><label>Provedor de fallback<select id="translation-fallback" name="translation_fallback_provider"><option value="none" <?=$fallbackProvider==='none'?'selected':''?>>Nenhum</option><option value="openai" <?=$fallbackProvider==='openai'?'selected':''?>>OpenAI</option><option value="gemini" <?=$fallbackProvider==='gemini'?'selected':''?>>Google Gemini</option><option value="google_cloud" <?=$fallbackProvider==='google_cloud'?'selected':''?>>Google Cloud Translation</option><option value="workers_ai" <?=($fallbackProvider==='workers_ai')?'selected':''?>>Cloudflare Workers AI</option></select></label><button class="saas-primary">Salvar prioridade →</button></form></section>
<div class="integrations-section-heading"><div><span class="saas-kicker">PROVEDORES DE TRADUÇÃO</span><h2>Credenciais e conexão</h2><p>Clique no card para expandir. Cada provedor mantém sua própria chave, teste de conexão, data/hora, latência e diagnóstico.</p></div></div><div class="translation-provider-grid">
<?php require __DIR__.'/app/google-cloud-card.php'; ?>
<section class="saas-card translation-provider-card openai-provider-card" style="margin:0!important;padding:0!important;">
  <div class="provider-head">
    <div><span class="saas-kicker">OPENAI</span><h2>OpenAI</h2>
      <p>Interpretação multimodal e tradução dos cards do Telegram Router.</p></div>
    <span class="form-status <?=$openaiKey&&$openaiEnabled?'is-configured':'is-empty'?>"><i></i><?=$openaiKey&&$openaiEnabled?'Configurado':'Não configurado'?></span>
  </div>
<form method="post" class="provider-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="provider" value="openai"><div class="saas-form-grid">
<label class="field-wide">API Key<span class="field-help"><?=$openaiKey?'Atual: '.sh(TranslationService::maskSecret($openaiKey)).'. Digite uma nova chave apenas para substituir.':'Informe uma API Key da OpenAI.'?></span><input name="openai_api_key" type="password" autocomplete="new-password" placeholder="<?=$openaiKey?'••••••••••••••••':'Cole a API Key da OpenAI'?>"></label>
<label class="field-wide openai-toggle"><input type="checkbox" name="openai_enabled" value="1" <?=$openaiEnabled?'checked':''?>><span class="openai-toggle-copy"><b>Ativar OpenAI</b><small>Habilita este provedor para interpretação, tradução e geração dos cards.</small></span></label>
<fieldset class="field-wide openai-models"><legend>Modelo principal</legend>
<label><input type="radio" name="openai_model" value="gpt-5.6-luna" <?=$openaiModel==='gpt-5.6-luna'?'checked':''?>><span><b>GPT-5.6 Luna</b><small>Econômico · processamento rápido e menor custo.</small></span></label>
<label><input type="radio" name="openai_model" value="gpt-5.6-terra" <?=$openaiModel==='gpt-5.6-terra'?'checked':''?>><span><b>GPT-5.6 Terra</b><small>Equilíbrio entre custo e interpretação de bilhetes complexos.</small></span></label>
<label><input type="radio" name="openai_model" value="gpt-5.6-sol" <?=$openaiModel==='gpt-5.6-sol'?'checked':''?>><span><b>GPT-5.6 Sol</b><small>Modelo avançado para interpretação complexa.</small></span></label>
<label><input type="radio" name="openai_model" value="gpt-6-astra" <?=$openaiModel==='gpt-6-astra'?'checked':''?>><span><b>GPT-6 Astra</b><small>Maior capacidade · indicado para bilhetes e análises mais complexas.</small></span></label>
</fieldset>
<label class="field-wide openai-toggle"><input type="checkbox" name="openai_fallback_enabled" value="1" <?=$openaiFallbackEnabled?'checked':''?>><span class="openai-toggle-copy"><b>Ativar fallback automático</b><small>Utiliza o modelo secundário caso o principal falhe.</small></span></label>
<label class="field-wide">Modelo de fallback<span class="field-help">Usado somente se o modelo principal não concluir a solicitação.</span><select name="openai_fallback_model"><option value="gpt-5.6-luna" <?=$openaiFallbackModel==='gpt-5.6-luna'?'selected':''?>>GPT-5.6 Luna</option><option value="gpt-5.6-terra" <?=$openaiFallbackModel==='gpt-5.6-terra'?'selected':''?>>GPT-5.6 Terra</option><option value="gpt-5.6-sol" <?=$openaiFallbackModel==='gpt-5.6-sol'?'selected':''?>>GPT-5.6 Sol</option><option value="gpt-6-astra" <?=$openaiFallbackModel==='gpt-6-astra'?'selected':''?>>GPT-6 Astra</option></select></label>
</div><div class="provider-actions"><button class="saas-primary" name="action" value="save_translation_provider">Salvar</button><button class="saas-secondary" name="action" value="test_translation_provider">Testar conexão</button></div></form>
<div class="provider-test <?=$openaiTest?((int)$openaiTest['last_test_ok']?'ok':'bad'):'neutral'?>"><b>Último teste</b><span><?=$openaiTest?((int)$openaiTest['last_test_ok']?'Conexão válida':'Falha'.(!empty($openaiTest['last_test_error'])?' — '.sh((string)$openaiTest['last_test_error']):(!empty($openaiTest['last_test_http_code'])?' — HTTP '.(int)$openaiTest['last_test_http_code']:''))):'Ainda não testado'?></span><small><?=$openaiTest?sh(dataHoraBrasil($openaiTest['last_test_at'])).' · '.(int)$openaiTest['last_test_latency_ms'].' ms':'—'?></small><?php if($openaiTest&&!$openaiTest['last_test_ok']&&!empty($openaiTest['last_test_error'])):?><em><?=sh($openaiTest['last_test_error'])?></em><?php endif;?></div>
<div class="provider-stats"><div><span>Traduções</span><b><?=sh($openaiStats['total'])?></b></div><div><span>Sucessos</span><b><?=sh($openaiStats['successful'])?></b></div><div><span>Falhas</span><b><?=sh($openaiStats['failures'])?></b></div><div><span>Latência média</span><b><?=sh($openaiStats['avg_latency'])?> ms</b></div><div><span>Última latência</span><b><?=sh($openaiStats['last_latency'])?> ms</b></div><div><span>Último uso</span><b><?=sh(dataHoraBrasil($openaiStats['last_use']))?></b></div></div></section>
<section class="saas-card translation-provider-card"><div class="saas-card-head"><div><span class="saas-kicker">GOOGLE AI</span><h2>Google Gemini</h2><p>Tradução com instrução curta e determinística, sem respostas adicionais.</p></div><span class="form-status <?=$geminiKey?'is-configured':'is-empty'?>"><i></i><?=$geminiKey?'Configurado':'Não configurado'?></span></div>
<form method="post" class="provider-form"><input type="hidden" name="csrf" value="<?=sh(Auth::csrf())?>"><input type="hidden" name="provider" value="gemini"><div class="saas-form-grid"><label class="field-wide">API Key<span class="field-help"><?=$geminiKey?'Atual: '.sh(TranslationService::maskSecret($geminiKey)).'. Digite uma nova chave apenas para substituir.':'Informe a chave do Google AI Studio.'?></span><input name="gemini_api_key" type="password" autocomplete="new-password" placeholder="<?=$geminiKey?'••••••••••••••••':'Cole a API Key do Gemini'?>"></label><label class="field-wide">Modelo<span class="field-help">Modelo utilizado nas traduções.</span><input name="gemini_model" value="<?=sh($geminiModel)?>" placeholder="gemini-3.6-flash"></label></div><div class="provider-actions"><button class="saas-primary" name="action" value="save_translation_provider">Salvar</button><button class="saas-secondary" name="action" value="test_translation_provider">Testar conexão</button></div></form>
<div class="provider-test <?=$geminiTest?((int)$geminiTest['last_test_ok']?'ok':'bad'):'neutral'?>"><b>Último teste</b><span><?=$geminiTest?(int)$geminiTest['last_test_ok']?'Conexão válida':'Falha'.(!empty($geminiTest['last_test_error'])?' — '.sh((string)$geminiTest['last_test_error']):(!empty($geminiTest['last_test_http_code'])?' — HTTP '.(int)$geminiTest['last_test_http_code']:'')):'Ainda não testado'?></span><small><?=$geminiTest?sh(dataHoraBrasil($geminiTest['last_test_at'])).' · '.(int)$geminiTest['last_test_latency_ms'].' ms':'—'?></small><?php if($geminiTest&&!$geminiTest['last_test_ok']&&!empty($geminiTest['last_test_error'])):?><em><?=sh($geminiTest['last_test_error'])?></em><?php endif;?></div>
<div class="provider-stats"><div><span>Traduções</span><b><?=sh($geminiStats['total'])?></b></div><div><span>Sucessos</span><b><?=sh($geminiStats['successful'])?></b></div><div><span>Falhas</span><b><?=sh($geminiStats['failures'])?></b></div><div><span>Latência média</span><b><?=sh($geminiStats['avg_latency'])?> ms</b></div><div><span>Última latência</span><b><?=sh($geminiStats['last_latency'])?> ms</b></div><div><span>Último uso</span><b><?=sh(dataHoraBrasil($geminiStats['last_use']))?></b></div></div></section>
<?php require __DIR__.'/app/workers-ai-card.php'; ?>
</div>
<script>document.addEventListener('DOMContentLoaded',()=>{const p=document.getElementById('translation-primary'),f=document.getElementById('translation-fallback');if(!p||!f)return;const sync=()=>{for(const o of f.options)o.disabled=o.value!=='none'&&o.value===p.value;if(f.value===p.value)f.value='none';};p.addEventListener('change',sync);sync();});</script>
<?php else:?><div class="saas-heading"><div><span class="saas-kicker">CENTRAL DE OPERAÇÃO</span><h1>Visão geral</h1><p>Acompanhe a conexão, as regras e cada tentativa de encaminhamento.</p></div><div class="saas-heading-actions"><a class="saas-secondary" href="/?page=events">Ver atividade</a><a class="saas-primary" href="/?page=rules">+ Nova regra</a></div></div>
<section class="overview-status <?=($status['status']??'desconectado')==='conectado'?'is-good':'is-alert'?>"><div class="overview-status-icon"><i></i></div><div><span class="saas-kicker">ESTADO DO SERVIÇO</span><h2><?=sh(match($status['status']??'desconectado'){'conectado'=>'Conectado','processando'=>'Processando','reconectando'=>'Reconectando','erro'=>'Erro','desconectado'=>'Desconectado',default=>'Estado não identificado'})?></h2><p><?=($status['status']??'')==='conectado'?'A conexão está ativa e o trabalhador está pronto para receber novas mensagens.':'O trabalhador não está em condição normal de processamento. Consulte os detalhes abaixo.'?></p></div><div class="overview-status-meta"><span>Última verificação</span><b><?=sh(dataHoraBrasil($status['last_activity_at']??null))?></b><a href="/connect.php">Gerenciar conexão →</a></div></section>
<?php if(($status['status']??'')==='erro' && !empty($status['last_error'])):?><section class="overview-alert"><b>Falha registrada no trabalhador</b><p><?=sh($status['last_error'])?></p><small>A conexão permanece monitorada. Verifique a conta e os canais configurados antes de tentar novamente.</small></section><?php endif;?>
<section class="saas-metrics overview-metrics"><div class="saas-metric"><div class="saas-metric-label">REGRAS ATIVAS <span class="saas-metric-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v10a2 2 0 0 0 2 2h4M18 17V7a2 2 0 0 0-2-2h-4m-2 12 2 2-2 2m4-18-2 2 2 2"/></svg></span></div><strong><?=sh($stats['active'])?></strong><small><?=sh($stats['total'])?> regras cadastradas</small></div><div class="saas-metric"><div class="saas-metric-label">ENCAMINHADAS HOJE <span class="saas-metric-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m21 3-7 18-4-7-7-4Zm0 0L10 14"/></svg></span></div><strong><?=sh($stats['forwarded']??0)?></strong><small>entregas concluídas</small></div><div class="saas-metric"><div class="saas-metric-label">TENTATIVAS COM FALHA <span class="saas-metric-icon danger"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m10 4-8 14a2 2 0 0 0 2 3h16a2 2 0 0 0 2-3L14 4a2 2 0 0 0-4 0ZM12 9v4m0 4h.01"/></svg></span></div><strong class="metric-danger"><?=sh($stats['failed']??0)?></strong><small>exigem atenção</small></div><div class="saas-metric"><div class="saas-metric-label">EVENTOS PROCESSADOS <span class="saas-metric-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span></div><strong><?=sh(($stats['forwarded']??0)+($stats['failed']??0)+($stats['skipped']??0))?></strong><small><?=sh($stats['skipped']??0)?> ignorados pelas regras</small></div></section>
<div class="saas-grid overview-grid"><section class="saas-card"><div class="saas-card-head"><div><span class="saas-kicker">MONITORAMENTO</span><h2>Últimas movimentações</h2><p>O que aconteceu recentemente no roteador.</p></div><a class="saas-link" href="/?page=events">Abrir histórico →</a></div><?php if(!$events):?><div class="saas-empty">Ainda não há mensagens processadas. O trabalhador continuará conectado e aguardando novos eventos.</div><?php else:?><div class="saas-table-wrap"><table class="saas-table"><thead><tr><th>Origem</th><th>Destino</th><th>Resultado</th><th>Quando</th></tr></thead><tbody><?php foreach(array_slice($events,0,6) as $e):?><tr><td><?=sh($e['source_chat'])?> · #<?=sh($e['message_id'])?></td><td><?=sh($e['destination_chat'])?></td><td><span class="saas-badge <?=sh(match($e['status']){'forwarded'=>'Encaminhada','failed'=>'Falhou','skipped'=>'Ignorada','processing'=>'Processando',default=>'Registrada'})?>"><?=sh(match($e['status']){'forwarded'=>'Encaminhada','failed'=>'Falhou','skipped'=>'Ignorada','processing'=>'Processando',default=>$e['status']})?></span></td><td><?=sh(dataHoraBrasil($e['created_at']))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section><section class="saas-card"><div class="saas-card-head"><div><span class="saas-kicker">CONFIGURAÇÃO</span><h2>Regras em operação</h2><p>Resumo das origens e destinos ativos.</p></div><a class="saas-link" href="/?page=rules">Gerenciar →</a></div><?php foreach(array_slice($rules,0,5) as $r):?><div class="saas-rule"><div class="saas-rule-top"><b><span class="saas-dot <?=((int)$r['enabled']?'':'off')?>"></span><?=sh($r['source_chat'])?></b><span class="rule-state"><?=((int)$r['enabled']?'Ativa':'Pausada')?></span></div><p><?=sh($r['trigger_text'])?> <span class="rule-arrow">→</span> <?=sh($r['destination_chat'])?></p></div><?php endforeach;if(!$rules):?><div class="saas-empty">Nenhuma regra criada. Configure a primeira para iniciar os encaminhamentos.</div><?php endif;?></section></div>
<section class="saas-card overview-guide"><div><span class="saas-kicker">COMO FUNCIONA</span><h2>O trabalhador permanece pronto</h2><p>Sem mensagens, o sistema não entra em espera: ele continua conectado, monitorando os canais e preparado para processar o próximo evento.</p></div><div class="guide-points"><span><i>1</i>Recebe a mensagem</span><span><i>2</i>Valida a regra</span><span><i>3</i>Encaminha ao destino</span></div></section><?php endif;?></main></div></div><script src="/assets/dialogs.js?v=3" defer></script><script src="/assets/live.js" defer></script><script src="/assets/toast.js" defer></script><script src="/assets/brand/integrations-v10.js?v=8" defer></script></body></html>
