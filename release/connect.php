<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/app/TelegramAuth.php';
use App\Auth; use App\TelegramAuth; use App\Repository;
Auth::requireLogin();

$jobDir=__DIR__.'/storage/auth-jobs';
if(!is_dir($jobDir)) @mkdir($jobDir,0700,true);
function jsonResponse(array $data,int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_UNESCAPED_UNICODE); exit; }
function startAuthJob(string $apiId,string $apiHash,string $phone): string {
    global $jobDir;
    $jobId=bin2hex(random_bytes(16));
    $file=$jobDir.'/'.$jobId.'.json';
    file_put_contents($file,json_encode(['status'=>'starting','api_id'=>$apiId,'api_hash'=>$apiHash,'phone'=>$phone,'created_at'=>time()],JSON_UNESCAPED_UNICODE),LOCK_EX);
    @chmod($file,0600);
    $script=__DIR__.'/scripts/telegram-auth-begin.php';
    $cmd='nohup '.escapeshellarg(chr(112).chr(104).chr(112)).' '.escapeshellarg($script).' '.escapeshellarg($jobId).' >/dev/null 2>&1 &';
    $process=@proc_open($cmd,[1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes);
    if(!is_resource($process)){ @unlink($file); throw new RuntimeException('Não foi possível iniciar o processo de conexão.'); }
    @proc_close($process);
    $_SESSION['telegram_auth_job']=$jobId;
    return $jobId;
}

if(isset($_GET['ajax']) && $_GET['ajax']==='begin_status') {
    $jobId=(string)($_SESSION['telegram_auth_job']??'');
    if(!$jobId || !hash_equals($jobId,(string)($_GET['job']??''))) jsonResponse(['status'=>'error','error'=>'Conexão inválida.'],403);
    $file=$jobDir.'/'.$jobId.'.json';
    $job=is_file($file)?(json_decode((string)file_get_contents($file),true)?:[]):[];
    $status=(string)($job['status']??'starting');
    if($status==='code') { $_SESSION['telegram_auth_step']='code'; $_SESSION['telegram_auth_job']=$jobId; }
    if($status==='ready') { unset($_SESSION['telegram_auth_job']); }
    jsonResponse(['status'=>$status,'error'=>$job['error']??null]);
}

$error=null; $notice=(string)($_SESSION['telegram_connect_notice']??''); unset($_SESSION['telegram_connect_notice']);
function startTelegramWorker(): void {
    $trabalhador=__DIR__.'/worker.php'; $log=__DIR__.'/storage/worker.log';
    if(!is_file($trabalhador) || !function_exists('proc_open')) return;
    if(!is_dir(dirname($log))) @mkdir(dirname($log),0700,true);
    $cmd='nohup '.escapeshellarg(chr(112).chr(104).chr(112)).' '.escapeshellarg($trabalhador).' >> '.escapeshellarg($log).' 2>&1 < /dev/null &';
    $process=@proc_open($cmd,[1=>['file','/dev/null','a'],2=>['file',$log,'a']],$pipes); if(is_resource($process)) @proc_close($process);
}
if($_SERVER['REQUEST_METHOD']==='POST') {
    try { Auth::verifyCsrf($_POST['csrf']??null); $action=$_POST['action']??'';
        if($action==='begin_async') {
            $apiId=trim((string)$_POST['api_id']); $apiHash=trim((string)$_POST['api_hash']); $phone=trim((string)$_POST['phone']);
            if(!ctype_digit($apiId)||(int)$apiId<=0) throw new InvalidArgumentException('O API ID deve ser numérico.');
            if($apiHash===''||strlen($apiHash)<20) throw new InvalidArgumentException('A chave da API parece incompleta.');
            if(!preg_match('/^\+[1-9][0-9]{7,15}$/',preg_replace('/[\s().-]+/','',$phone))) throw new InvalidArgumentException('Informe o telefone no formato internacional, por exemplo +5511999999999.');
            $_SESSION['telegram_api_id']=$apiId; $_SESSION['telegram_api_hash']=$apiHash; $_SESSION['telegram_phone']=$phone;
            $jobId=startAuthJob($apiId,$apiHash,$phone); jsonResponse(['status'=>'starting','job'=>$jobId]);
        }
        if($action==='code') { $step=TelegramAuth::confirmCode(trim((string)$_POST['code'])); if($step!=='password') startTelegramWorker(); $notice=$step==='password'?'Informe a senha verificação em duas etapas.':'Conta conectada com sucesso.'; }
        elseif($action==='password') { TelegramAuth::confirmPassword((string)$_POST['password']); startTelegramWorker(); $notice='Conta conectada com sucesso.'; }
        elseif($action==='cancel') { TelegramAuth::cancel(); $notice='Fluxo cancelado.'; }
        elseif($action==='disconnect') { TelegramAuth::disconnect(); $notice='Conta Telegram desconectada com segurança.'; }
    } catch(Throwable $e) { if(isset($_POST['action'])&&$_POST['action']==='begin_async') jsonResponse(['status'=>'error','error'=>App\ErrorTranslator::message($e, 'o início da conexão Telegram')],422); $error=App\ErrorTranslator::message($e, 'a operação solicitada'); }
    if($error===null){ $_SESSION['telegram_connect_notice']=$notice; header('Location: connect.php', true, 303); exit; }
}
$step=$_SESSION['telegram_auth_step']??null; $credentials=Repository::credentials(); $connected=!empty($credentials['telegram_session']); $connectedPhone=(string)($credentials['telegram_phone']??'');if($connectedPhone!==''&&$connectedPhone[0]!=='+')$connectedPhone='+'.$connectedPhone; $apiIdValue=(string)($credentials['telegram_api_id']??''); $apiHashValue=(string)($credentials['telegram_api_hash']??'');
function eh(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Telegram</title><link rel="stylesheet" href="/assets/pro.css"><style>.connect-progress{width:100%;margin-top:18px;padding:16px 18px;border:1px solid rgba(118,243,228,.18);border-radius:14px;background:linear-gradient(135deg,rgba(118,243,228,.08),rgba(255,255,255,.025));box-sizing:border-box}.progress-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px;color:#d8e9ed;font-size:.9rem}.progress-head strong{color:#76f3e4;font-size:.95rem;font-variant-numeric:tabular-nums}.connect-progress progress{display:block;width:100%;height:10px;border:0;border-radius:999px;overflow:hidden;background:#1b2b32;accent-color:#76f3e4}.connect-progress progress::-webkit-progress-bar{background:#1b2b32;border-radius:999px}.connect-progress progress::-webkit-progress-value{border-radius:999px;background:linear-gradient(90deg,#3acdc1,#76f3e4,#b2fff5);box-shadow:0 0 14px rgba(118,243,228,.45);transition:width .6s ease}.connect-progress progress::-moz-progress-bar{border-radius:999px;background:linear-gradient(90deg,#3acdc1,#76f3e4,#b2fff5);box-shadow:0 0 14px rgba(118,243,228,.45)}.progress-caption{display:flex;flex-direction:column;gap:4px;margin-top:10px;color:#aab8c8;line-height:1.45}.progress-caption small{font-size:.82rem}.progress-hint{color:#718892}</style><link rel="stylesheet" href="/assets/responsive.css?v=4"><style id="tmr-icons-v1">
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
</style><link rel="stylesheet" href="/assets/brand/brand.css?v=2"><link rel="stylesheet" href="/assets/brand/mobile-shell.css?v=2"><link rel="stylesheet" href="/assets/brand/integrations-v10.css?v=8&workers-dot=5"><link rel="stylesheet" href="/assets/brand/activity-status-badges.css?v=4"><link rel="stylesheet" href="/assets/brand/horizontal-scrollbar.css?v=1"><link rel="stylesheet" href="/assets/brand/mobile-visual-audit.css?v=15"><link rel="stylesheet" href="/assets/brand/service-status-responsive.css?v=1"><link rel="stylesheet" href="/assets/brand/connect-responsive.css?v=2"><link rel="stylesheet" href="/assets/brand/orchestration-responsive.css?v=1"><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3"><link rel="manifest" href="/manifest.webmanifest?v=2"><meta name="theme-color" content="#0B1220"></head><body><div class="connect-shell"><div class="connect-top"><a href="/">← Voltar ao painel</a><span class="connection-status <?=$connected?'online':'offline'?>"><i></i><?=$connected?'Conta conectada':'Não conectado'?></span></div><section class="connect-hero"><span class="hero-symbol"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m21 3-7 18-4-7-7-4Zm0 0L10 14"/></svg></span><span class="eyebrow">CONEXÃO PROTEGIDA</span><h1>Conecte sua conta Telegram</h1><p>Configure a conta uma única vez. A sessão será mantida e o trabalhador continuará conectado automaticamente.</p></section><section class="card connect-panel"><div class="steps"><span class="step <?=!$step?'current':''?>"><b>01</b> Dados da aplicação</span><span class="line"></span><span class="step <?=$step==='code'?'current':''?>"><b>02</b> Código recebido</span><span class="line"></span><span class="step <?=$step==='password'?'current':''?>"><b>03</b> verificação em duas etapas</span></div><?php if($error):?><div class="flash error"><?=eh($error)?></div><?php endif;?><?php if($notice):?><div class="flash success"><?=eh($notice)?></div><?php endif;?><?php if($step==='code'):?><div class="form-intro"><h2>Verifique seu telefone</h2><p>Digite o código enviado pelo Telegram para continuar.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=eh(Auth::csrf())?>"><input type="hidden" name="action" value="code"><label>Código de confirmação<input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required placeholder="12345"></label><button>Confirmar código →</button></form><form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=eh(Auth::csrf())?>"><input type="hidden" name="action" value="cancel"><button type="submit" style="background:#ffffff12;color:#aab8c8">← Voltar e reiniciar</button></form><?php elseif($step==='password'):?><div class="form-intro"><h2>Autenticação em duas etapas</h2><p>Sua conta exige uma senha de verificação em duas etapas. Ela será usada apenas durante esta autenticação.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=eh(Auth::csrf())?>"><input type="hidden" name="action" value="password"><label>Senha da verificação em duas etapas<input name="password" type="password" autofocus required></label><button>Concluir conexão →</button></form><?php elseif($connected):?><div class="form-intro"><h2>Conta Telegram conectada</h2><p class="connected-number">Número conectado: <strong><?=eh($connectedPhone ?: 'número não identificado')?></strong></p><p>A sessão foi autenticada e está pronta para o trabalhador. Você já pode criar regras de roteamento.</p><a href="/?page=rules" style="display:inline-block;margin-top:14px;padding:12px 16px;border-radius:9px;background:#76f3e4;color:#061015;text-decoration:none;font-weight:700">Configurar regras →</a><form method="post" class="disconnect-form"><input type="hidden" name="csrf" value="<?=eh(Auth::csrf())?>"><input type="hidden" name="action" value="disconnect"><button type="submit" class="disconnect-button">Desconectar Telegram</button></form></div><?php else: ?><div class="form-intro"><h2>Dados da aplicação Telegram</h2><p>Informe os dados da sua aplicação Telegram. Consulte-os em <b>my.telegram.org</b>. Eles identificam sua aplicação, não sua senha.</p></div><form id="begin-form" method="post"><input type="hidden" name="csrf" value="<?=eh(Auth::csrf())?>"><input type="hidden" name="action" value="begin_async"><div class="form-grid two"><label>Identificador da aplicação<input name="api_id" inputmode="numeric" required value="<?=eh($apiIdValue)?>" placeholder="12345678"></label><label>Chave da aplicação<input name="api_hash" type="password" required value="<?=eh($apiHashValue)?>" placeholder="Chave da aplicação"></label></div><label>Número de telefone<input name="phone" type="tel" required placeholder="+55 11 99999-9999"></label><button id="begin-button">Enviar código pelo Telegram →</button><div id="begin-status" class="connect-progress" hidden aria-live="polite"><div class="progress-head"><span id="progress-label">Solicitando o código ao Telegram…</span><strong id="progress-percent">0%</strong></div><progress id="progress-bar" max="100" value="0" aria-label="Progresso da conexão"></progress><div class="progress-caption"><small id="progress-detail">Aguarde enquanto o Telegram envia o código para o seu celular.</small><small class="progress-hint">A conexão pode levar alguns segundos.</small></div></div></form><?php endif;?></section><div class="security-note"><span>✓</span><div><b>Seus dados permanecem protegidos</b><p>A sessão é cifrada com AES-256-GCM no banco e não é exibida novamente no painel.</p></div></div></div><script>
const form=document.getElementById('begin-form');
if(form){const button=document.getElementById('begin-button'),status=document.getElementById('begin-status'),bar=document.getElementById('progress-bar'),percent=document.getElementById('progress-percent'),label=document.getElementById('progress-label'),detail=document.getElementById('progress-detail');let timer,started;
 const render=()=>{const elapsed=Math.floor((Date.now()-started)/1000),estimate=Math.min(90,8+Math.floor(elapsed*1.2));bar.value=estimate;percent.textContent=estimate+'%';detail.textContent='Aguardando resposta do Telegram… '+elapsed+'s';};
 form.addEventListener('submit',async e=>{e.preventDefault();button.disabled=true;button.textContent='Aguardando Telegram…';status.hidden=false;started=Date.now();render();const tick=setInterval(render,1000);try{const r=await fetch('connect.php',{method:'POST',body:new FormData(form),credentials:'same-origin'});const data=await r.json();if(!r.ok||data.status==='error')throw new Error(data.error||'Falha ao iniciar conexão.');
 const poll=async()=>{try{const resp=await fetch('connect.php?ajax=begin_status&job='+encodeURIComponent(data.job),{credentials:'same-origin',cache:'no-store'});const j=await resp.json();if(j.status==='code'||j.status==='ready'){clearInterval(tick);clearTimeout(timer);bar.value=100;percent.textContent='100%';label.textContent=j.status==='ready'?'Conexão concluída':'Código enviado pelo Telegram';detail.textContent=j.status==='ready'?'Redirecionando…':'Digite o código recebido no Telegram.';setTimeout(()=>location.replace('connect.php'),350);return;}if(j.status==='error')throw new Error(j.error||'Falha ao conectar.');timer=setTimeout(poll,1200);}catch(err){clearInterval(tick);status.hidden=false;label.textContent='Não foi possível concluir';detail.textContent=err.message;button.disabled=false;button.textContent='Tentar novamente →';}};poll();
 }catch(err){clearInterval(tick);label.textContent='Não foi possível iniciar';detail.textContent=err.message;button.disabled=false;button.textContent='Tentar novamente →';}});}
</script><script src="/assets/dialogs.js?v=2" defer></script><script src="/assets/toast.js" defer></script></body></html>
