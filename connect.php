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
    $cmd='nohup '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($jobId).' >/dev/null 2>&1 &';
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

$error=null; $notice=null;
function startTelegramWorker(): void {
    $trabalhador=__DIR__.'/worker.php'; $log=__DIR__.'/storage/worker.log';
    if(!is_file($trabalhador) || !function_exists('proc_open')) return;
    if(!is_dir(dirname($log))) @mkdir(dirname($log),0700,true);
    $cmd='nohup '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($trabalhador).' >> '.escapeshellarg($log).' 2>&1 < /dev/null &';
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
}
$step=$_SESSION['telegram_auth_step']??null; $credentials=Repository::credentials(); $connected=!empty($credentials['telegram_session']); $connectedPhone=(string)($credentials['telegram_phone']??'');if($connectedPhone!==''&&$connectedPhone[0]!=='+')$connectedPhone='+'.$connectedPhone; $apiIdValue=(string)($credentials['telegram_api_id']??''); $apiHashValue=(string)($credentials['telegram_api_hash']??'');
function eh(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Conectar Telegram</title><link rel="stylesheet" href="/assets/pro.css"></head><body><div class="connect-shell"><div class="connect-top"><a href="/">← Voltar ao painel</a><span class="connection-status <?=$connected?'online':'offline'?>"><i></i><?=$connected?'Conta conectada':'Não conectado'?></span></div><section class="connect-hero"><span class="hero-symbol">⌁</span><span class="eyebrow">CONEXÃO PROTEGIDA</span><h1>Conecte sua conta Telegram</h1><p>Configure a conta uma única vez. A sessão será mantida e o trabalhador continuará conectado automaticamente.</p></section><section class="card connect-panel"><div class="steps"><span class="step <?=!$step?'current':''?>"><b>01</b> Dados da aplicação</span><span class="line"></span><span class="step <?=$step==='code'?'current':''?>"><b>02</b> Código recebido</span><span class="line"></span><span class="step <?=$step==='password'?'current':''?>"><b>03</b> verificação em duas etapas</span></div><?php if($error):?><div class="flash error"><?=eh($error)?></div><?php endif;?><?php if($notice):?><div class="flash success"><?=eh($notice)?></div><?php endif;?><?php if($step==='code'):?><div class="form-intro"><h2>Verifique seu telefone</h2><p>Digite o código enviado pelo Telegram para continuar.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=eh(Auth::csrf())?>"><input type="hidden" name="action" value="code"><label>Código de confirmação<input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required placeholder="12345"></label><button>Confirmar código →</button></form><form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?=eh(Auth::csrf())?>"><input type="hidden" name="action" value="cancel"><button type="submit" style="background:#ffffff12;color:#aab8c8">← Voltar e reiniciar</button></form><?php elseif($step==='password'):?><div class="form-intro"><h2>Autenticação em duas etapas</h2><p>Sua conta exige uma senha de verificação em duas etapas. Ela será usada apenas durante esta autenticação.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=eh(Auth::csrf())?>"><input type="hidden" name="action" value="password"><label>Senha da verificação em duas etapas<input name="password" type="password" autofocus required></label><button>Concluir conexão →</button></form><?php elseif($connected):?><div class="form-intro"><h2>Conta Telegram conectada</h2><p class="connected-number">Número conectado: <strong><?=eh($connectedPhone ?: 'número não identificado')?></strong></p><p>A sessão foi autenticada e está pronta para o trabalhador. Você já pode criar regras de roteamento.</p><a href="/?page=rules" style="display:inline-block;margin-top:14px;padding:12px 16px;border-radius:9px;background:#76f3e4;color:#061015;text-decoration:none;font-weight:700">Configurar regras →</a><form method="post" class="disconnect-form"><input type="hidden" name="csrf" value="<?=eh(Auth::csrf())?>"><input type="hidden" name="action" value="disconnect"><button type="submit" class="disconnect-button">Desconectar Telegram</button></form></div><?php else: ?><div class="form-intro"><h2>Dados da aplicação Telegram</h2><p>Informe os dados da sua aplicação Telegram. Consulte-os em <b>my.telegram.org</b>. Eles identificam sua aplicação, não sua senha.</p></div><form id="begin-form" method="post"><input type="hidden" name="csrf" value="<?=eh(Auth::csrf())?>"><input type="hidden" name="action" value="begin_async"><div class="form-grid two"><label>Identificador da aplicação<input name="api_id" inputmode="numeric" required value="<?=eh($apiIdValue)?>" placeholder="12345678"></label><label>Chave da aplicação<input name="api_hash" type="password" required value="<?=eh($apiHashValue)?>" placeholder="Chave da aplicação"></label></div><label>Número de telefone<input name="phone" type="tel" required placeholder="+55 11 99999-9999"></label><button id="begin-button">Enviar código pelo Telegram →</button><p id="begin-status" style="display:none;margin-top:12px;color:#aab8c8">Preparando a conexão segura com o Telegram…</p></form><?php endif;?></section><div class="security-note"><span>✓</span><div><b>Seus dados permanecem protegidos</b><p>A sessão é cifrada com AES-256-GCM no banco e não é exibida novamente no painel.</p></div></div></div><script>
const form=document.getElementById('begin-form');
if(form){const button=document.getElementById('begin-button'),status=document.getElementById('begin-status');form.addEventListener('submit',async e=>{e.preventDefault();button.disabled=true;button.textContent='Conectando…';status.style.display='block';try{const r=await fetch('connect.php',{method:'POST',body:new FormData(form),credentials:'same-origin'});const data=await r.json();if(data.status==='error'){if(window.tmrToast)window.tmrToast(data.error||'Falha ao iniciar conexão.','error');throw new Error(data.error||'Falha ao iniciar conexão.');}const timer=setInterval(async()=>{try{const s=await fetch('connect.php?ajax=begin_status&job='+encodeURIComponent(data.job),{credentials:'same-origin'});const j=await s.json();if(j.status==='code'||j.status==='ready'){clearInterval(timer);if(window.tmrToast)window.tmrToast(j.status==='ready'?'Conta conectada com sucesso.':'Código enviado pelo Telegram.','success');location.reload();}else if(j.status==='error'){clearInterval(timer);status.textContent=j.error||'Falha ao conectar.';if(window.tmrToast)window.tmrToast(j.error||'Falha ao conectar.','error');button.disabled=false;button.textContent='Tentar novamente →';}}catch(_){}} ,1200);}catch(err){status.textContent=err.message;if(window.tmrToast)window.tmrToast(err.message,'error');button.disabled=false;button.textContent='Tentar novamente →';}});}
</script><script src="/assets/dialogs.js" defer></script><script src="/assets/toast.js" defer></script></body></html>
