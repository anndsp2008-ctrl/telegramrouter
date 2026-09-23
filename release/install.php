<?php declare(strict_types=1);

$envFile=__DIR__.'/.env';
$installed=is_file($envFile);
$error=null; $success=null;
function h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function post(string $key,string $default=''): string { return trim((string)($_POST[$key]??$default)); }
if($_SERVER['REQUEST_METHOD']==='POST' && !$installed){
    $host=post('db_host','localhost'); $port=post('db_port','3306'); $name=post('db_name'); $user=post('db_user'); $pass=(string)($_POST['db_pass']??'');
    $panelUser=post('panel_username','admin'); $panelPass=(string)($_POST['panel_password']??''); $panelPassConfirm=(string)($_POST['panel_password_confirm']??'');
    $appUrl=post('app_url'); $apiId=post('telegram_api_id'); $apiHash=post('telegram_api_hash');
    if($name===''||$user==='') $error='Preencha o nome do banco e o usuário do banco.';
    elseif($panelUser===''||strlen($panelUser)<3) $error='O usuário inicial deve ter pelo menos 3 caracteres.';
    elseif(strlen($panelPass)<8) $error='A senha inicial deve ter pelo menos 8 caracteres.';
    elseif(!hash_equals($panelPass,$panelPassConfirm)) $error='A confirmação da senha inicial não confere.';
    elseif(!preg_match('/^[A-Za-z0-9_$-]+$/',$name)) $error='O nome do banco contém caracteres inválidos.';
    elseif(!ctype_digit($port)||(int)$port<1||(int)$port>65535) $error='A porta do banco é inválida.';
    else {
        try {
            $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
            try { $pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,$options); }
            catch(PDOException $first) { $pdo=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,$options); $safeName=str_replace('`','``',$name); $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $pdo->exec("USE `{$safeName}`"); }
            $schema=file_get_contents(__DIR__.'/database/schema.sql');
            if($schema===false) throw new RuntimeException('O esquema do banco não foi encontrado.');
            $pdo->exec($schema);
            $lines=[
                'APP_ENV=production','APP_URL='.str_replace(["\r","\n"],'',$appUrl),'APP_KEY='.bin2hex(random_bytes(32)),
                'DB_HOST='.str_replace(["\r","\n"],'',$host),'DB_PORT='.$port,'DB_NAME='.str_replace(["\r","\n"],'',$name),
                'DB_USER='.str_replace(["\r","\n"],'',$user),'DB_PASS='.str_replace(["\r","\n"],'',$pass),
                'PANEL_USERNAME='.str_replace(["\r","\n"],'',$panelUser),'PANEL_PASSWORD_HASH='.password_hash($panelPass,PASSWORD_DEFAULT),
                'TELEGRAM_API_ID='.str_replace(["\r","\n"],'',$apiId),'TELEGRAM_API_HASH='.str_replace(["\r","\n"],'',$apiHash),
                'TELEGRAM_SESSION=','TELEGRAM_OWNER_USER_ID=','TELEGRAM_ENABLED=0','LOG_LEVEL=INFO'
            ];
            $tmp=$envFile.'.'.bin2hex(random_bytes(8)).'.tmp'; file_put_contents($tmp,implode("\n",$lines)."\n",LOCK_EX); chmod($tmp,0600); rename($tmp,$envFile); $success='Instalação concluída. O arquivo de configuração foi criado com segurança.'; $installed=true;
        } catch(Throwable $e) { $error='Não foi possível concluir a instalação: '. $e->getMessage(); }
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalação · Telegram Media Router</title><style>:root{font-family:Inter,system-ui,sans-serif;color:#eaf5f4;background:#071016}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at 15% 0,#16434a,#071016 50%);padding:30px 18px}.wrap{max-width:820px;margin:auto}.brand{color:#78efe4;font-weight:800;letter-spacing:.12em;font-size:11px}.card{margin-top:18px;background:#101e26;border:1px solid #ffffff18;border-radius:18px;padding:28px;box-shadow:0 25px 80px #0008}h1{font-size:30px;margin:10px 0 8px}h2{font-size:17px;margin:26px 0 12px;color:#a7f4ed}p{color:#91a8ae;line-height:1.6;font-size:13px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}label{display:block;color:#a9bdc1;font-size:12px;margin:12px 0}input{display:block;width:100%;margin-top:7px;padding:12px;border:1px solid #ffffff1c;border-radius:9px;background:#071218;color:#efffff;font:inherit}button{margin-top:20px;padding:13px 19px;border:0;border-radius:9px;background:#6de9df;color:#061215;font-weight:800;cursor:pointer}.alert{padding:13px 15px;border-radius:9px;margin:14px 0;font-size:13px}.error{background:#ff6f7d18;color:#ffb1b8;border:1px solid #ff6f7d44}.success{background:#58e2ae18;color:#9bf4c9;border:1px solid #58e2ae44}.note{font-size:12px;color:#81999f;margin-top:16px}@media(max-width:650px){.grid{grid-template-columns:1fr}.card{padding:21px}h1{font-size:25px}}</style><link rel="stylesheet" href="/assets/responsive.css?v=4">

<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMR">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/pwa/apple-touch-icon.png?v=1">
<script defer src="/assets/pwa.js?v=1"></script>
<style id="tmr-scrollbar-v1">
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
</style><link rel="stylesheet" href="/assets/brand/brand.css?v=2"><link rel="stylesheet" href="/assets/brand/mobile-shell.css?v=2"><link rel="stylesheet" href="/assets/brand/integrations-v10.css?v=8&workers-dot=5"><link rel="stylesheet" href="/assets/brand/activity-status-badges.css?v=4"><link rel="stylesheet" href="/assets/brand/horizontal-scrollbar.css?v=1"><link rel="stylesheet" href="/assets/brand/mobile-visual-audit.css?v=15"><link rel="stylesheet" href="/assets/brand/service-status-responsive.css?v=1"><link rel="stylesheet" href="/assets/brand/connect-responsive.css?v=2"><link rel="stylesheet" href="/assets/brand/orchestration-responsive.css?v=1"><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3"><link rel="manifest" href="/manifest.webmanifest?v=2"><meta name="theme-color" content="#0B1220"></head><body><main class="wrap"><div class="brand">TELEGRAM MEDIA ROUTER</div><section class="card"><h1>Instalação do sistema</h1><?php if($installed&&!$success):?><div class="alert error">Este projeto já está instalado. Para reinstalar, remova o arquivo <strong>.env</strong> manualmente pelo servidor.</div><?php elseif($success):?><div class="alert success"><?=h($success)?></div><p>Agora importe a sessão do Telegram pelo módulo de conexão e acesse o painel.</p><a href="/login.php" style="color:#84eee4">Ir para o painel de acesso</a><?php else:?><p>Configure o banco de dados e o acesso inicial. O instalador criará as tabelas, gerará a chave da aplicação e salvará as credenciais em um arquivo protegido.</p><?php if($error):?><div class="alert error"><?=h($error)?></div><?php endif;?><form method="post"><h2>Banco de dados</h2><div class="grid"><label>Servidor<input name="db_host" required value="<?=h($_POST['db_host']??'localhost')?>"></label><label>Porta<input name="db_port" inputmode="numeric" required value="<?=h($_POST['db_port']??'3306')?>"></label><label>Nome do banco<input name="db_name" required value="<?=h($_POST['db_name']??'')?>"></label><label>Usuário<input name="db_user" required value="<?=h($_POST['db_user']??'')?>"></label></div><label>Senha do banco<input name="db_pass" type="password"></label><h2>Acesso inicial ao painel</h2><div class="grid"><label>Usuário inicial<input name="panel_username" required value="<?=h($_POST['panel_username']??'admin')?>"></label><label>Endereço do sistema<input name="app_url" type="url" placeholder="https://seu-dominio.com" value="<?=h($_POST['app_url']??'')?>"></label></div><div class="grid"><label>Senha inicial<input name="panel_password" type="password" minlength="8" required></label><label>Confirmar senha<input name="panel_password_confirm" type="password" minlength="8" required></label></div><h2>Telegram — opcional</h2><div class="grid"><label>API ID<input name="telegram_api_id" inputmode="numeric" value="<?=h($_POST['telegram_api_id']??'')?>"></label><label>Chave da API<input name="telegram_api_hash" value="<?=h($_POST['telegram_api_hash']??'')?>"></label></div><button type="submit">Instalar sistema</button><p class="note">Após a instalação, apague ou bloqueie este arquivo install.php para impedir novas tentativas de instalação.</p></form><?php endif;?></section></main></body></html>
