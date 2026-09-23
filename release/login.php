<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Auth;

if (Auth::check()) {
    header('Location: /');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::login(trim($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: /');
        exit;
    }
    $error = 'Usuário ou senha inválidos.';
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<title>Acesso seguro · Telegram Media Router</title>

<style>
:root {
  --bg: #061116;
  --panel: rgba(7, 22, 29, .92);
  --panel-2: rgba(9, 31, 38, .78);
  --border: rgba(91, 216, 207, .20);
  --border-strong: rgba(91, 216, 207, .42);
  --text: #edf8f7;
  --muted: #91a9ad;
  --accent: #6fddd2;
  --accent-2: #46bdb3;
  --danger: #ff818c;
  --shadow: 0 28px 70px rgba(0, 0, 0, .46);
}

* { box-sizing: border-box; }
html { min-height: 100%; background: var(--bg); }
body {
  margin: 0;
  min-width: 320px;
  min-height: 100vh;
  min-height: 100dvh;
  color: var(--text);
  font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  background:
    radial-gradient(circle at 15% 20%, rgba(37, 132, 128, .20), transparent 34%),
    radial-gradient(circle at 88% 82%, rgba(53, 84, 176, .17), transparent 30%),
    linear-gradient(135deg, #07161b 0%, #050d12 54%, #07101a 100%);
  overflow-x: hidden;
}
body::before {
  content: "";
  position: fixed;
  inset: 0;
  pointer-events: none;
  opacity: .22;
  background-image:
    linear-gradient(rgba(116, 216, 209, .08) 1px, transparent 1px),
    linear-gradient(90deg, rgba(116, 216, 209, .08) 1px, transparent 1px);
  background-size: 52px 52px;
  mask-image: linear-gradient(to bottom, rgba(0,0,0,.85), transparent 78%);
}

.tmr-login-shell {
  position: relative;
  z-index: 1;
  display: flex;
  width: 100%;
  min-height: 100vh;
  min-height: 100dvh;
  padding:
    max(20px, env(safe-area-inset-top))
    max(16px, env(safe-area-inset-right))
    max(20px, env(safe-area-inset-bottom))
    max(16px, env(safe-area-inset-left));
}

.tmr-login-card {
  width: min(100%, 460px);
  margin: auto;
  padding: clamp(24px, 5vw, 38px);
  border: 1px solid var(--border);
  border-radius: 28px;
  background: linear-gradient(145deg, var(--panel), var(--panel-2));
  box-shadow: var(--shadow), inset 0 1px 0 rgba(255,255,255,.035);
  backdrop-filter: blur(18px);
  -webkit-backdrop-filter: blur(18px);
}

.tmr-brand {
  display: flex;
  align-items: center;
  gap: 13px;
  margin-bottom: 27px;
}
.tmr-brand img {
  display: block;
  width: 48px;
  height: 48px;
  object-fit: contain;
  flex: 0 0 48px;
}
.tmr-brand-copy { min-width: 0; }
.tmr-brand-name {
  margin: 0;
  font-size: 15px;
  font-weight: 800;
  letter-spacing: .015em;
  color: #f3fffe;
}
.tmr-brand-sub {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--muted);
}

.tmr-eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  color: var(--accent);
  font-size: 12px;
  font-weight: 800;
  letter-spacing: .09em;
  text-transform: uppercase;
}
.tmr-eyebrow::before {
  content: "";
  width: 7px;
  height: 7px;
  border-radius: 999px;
  background: var(--accent);
  box-shadow: 0 0 18px rgba(111, 221, 210, .72);
}

h1 {
  margin: 0;
  font-size: clamp(26px, 6vw, 34px);
  line-height: 1.12;
  letter-spacing: -.035em;
}
.tmr-intro {
  margin: 12px 0 26px;
  color: var(--muted);
  font-size: 14px;
  line-height: 1.6;
}

.tmr-error {
  margin: 0 0 18px;
  padding: 12px 14px;
  border: 1px solid rgba(255, 129, 140, .28);
  border-radius: 14px;
  background: rgba(128, 25, 36, .17);
  color: #ffb3ba;
  font-size: 13px;
}

.tmr-field { margin-top: 17px; }
.tmr-label {
  display: block;
  margin: 0 0 8px;
  color: #cbdcde;
  font-size: 12px;
  font-weight: 700;
}
.tmr-input-wrap { position: relative; }
.tmr-input {
  display: block;
  width: 100%;
  min-height: 54px;
  margin: 0;
  padding: 0 48px 0 16px;
  border: 1px solid rgba(127, 172, 174, .24);
  border-radius: 15px;
  outline: none;
  background: rgba(2, 13, 18, .74);
  color: #f1fbfb;
  font: inherit;
  font-size: 16px;
  transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
  appearance: none;
  -webkit-appearance: none;
}
.tmr-input::placeholder { color: #6f8488; }
.tmr-input:focus {
  border-color: var(--border-strong);
  background: rgba(4, 18, 24, .92);
  box-shadow: 0 0 0 3px rgba(84, 204, 195, .10);
}
.tmr-field-icon {
  position: absolute;
  right: 16px;
  top: 50%;
  transform: translateY(-50%);
  width: 20px;
  height: 20px;
  color: #6e898d;
  pointer-events: none;
}
.tmr-password-toggle {
  position: absolute;
  top: 50%;
  right: 9px;
  transform: translateY(-50%);
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  padding: 0;
  border: 0;
  border-radius: 10px;
  background: transparent;
  color: #87a1a4;
  cursor: pointer;
}
.tmr-password-toggle:hover { background: rgba(255,255,255,.05); color: #d7eeee; }

.tmr-submit {
  width: 100%;
  min-height: 54px;
  margin-top: 24px;
  border: 1px solid rgba(126, 236, 225, .32);
  border-radius: 15px;
  background: linear-gradient(135deg, #4ec6bb, #2f9f99);
  color: #031315;
  font: inherit;
  font-weight: 850;
  font-size: 15px;
  letter-spacing: .01em;
  cursor: pointer;
  box-shadow: 0 14px 30px rgba(37, 143, 136, .20);
}
.tmr-submit:hover { filter: brightness(1.06); }
.tmr-submit:active { transform: translateY(1px); }

.tmr-status {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 8px;
  margin: 22px 0 0;
  padding: 0;
  list-style: none;
}
.tmr-status li {
  padding: 7px 10px;
  border: 1px solid rgba(111, 221, 210, .12);
  border-radius: 999px;
  background: rgba(111, 221, 210, .045);
  color: #87a5a7;
  font-size: 10px;
  white-space: nowrap;
}

@media (max-width: 520px) {
  .tmr-login-shell { padding: max(14px, env(safe-area-inset-top)) 12px max(14px, env(safe-area-inset-bottom)); }
  .tmr-login-card { border-radius: 22px; padding: 24px 20px; }
  .tmr-brand { margin-bottom: 22px; }
  .tmr-brand img { width: 44px; height: 44px; flex-basis: 44px; }
  .tmr-status { gap: 6px; }
  .tmr-status li { font-size: 9px; padding: 6px 8px; }
}

@media (max-height: 650px) {
  .tmr-login-shell { padding-top: 12px; padding-bottom: 12px; }
  .tmr-login-card { padding-top: 20px; padding-bottom: 20px; }
  .tmr-brand { margin-bottom: 16px; }
  .tmr-intro { margin-bottom: 18px; }
  .tmr-field { margin-top: 12px; }
  .tmr-submit { margin-top: 18px; }
  .tmr-status { margin-top: 16px; }
}

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { scroll-behavior: auto !important; transition: none !important; }
}
</style>


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
</style><link rel="stylesheet" href="/assets/brand/brand.css?v=2"><link rel="stylesheet" href="/assets/brand/mobile-shell.css?v=2"><link rel="stylesheet" href="/assets/brand/integrations-v10.css?v=8&workers-dot=5"><link rel="stylesheet" href="/assets/brand/activity-status-badges.css?v=4"><link rel="stylesheet" href="/assets/brand/horizontal-scrollbar.css?v=1"><link rel="stylesheet" href="/assets/brand/mobile-visual-audit.css?v=15"><link rel="stylesheet" href="/assets/brand/service-status-responsive.css?v=1"><link rel="stylesheet" href="/assets/brand/connect-responsive.css?v=2"><link rel="stylesheet" href="/assets/brand/orchestration-responsive.css?v=1"><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3"><link rel="manifest" href="/manifest.webmanifest?v=2"><meta name="theme-color" content="#0B1220"></head>
<body>
<main class="tmr-login-shell">
  <section class="tmr-login-card" aria-labelledby="login-title">
    <div class="tmr-brand">
      <img src="/assets/tmr-logo.svg?v=4" alt="Telegram Media Router">
      <div class="tmr-brand-copy">
        <p class="tmr-brand-name">Telegram Media Router</p>
        <p class="tmr-brand-sub">Roteamento inteligente · operação 24/7</p>
      </div>
    </div>

    <span class="tmr-eyebrow">Acesso seguro</span>
    <h1 id="login-title">Entre no painel</h1>
    <p class="tmr-intro">Gerencie regras, integrações e atividades do seu roteador em um ambiente protegido.</p>

    <?php if ($error): ?>
      <div class="tmr-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <div class="tmr-field">
        <label class="tmr-label" for="username">Usuário</label>
        <div class="tmr-input-wrap">
          <input class="tmr-input" id="username" name="username" type="text" autocomplete="username" placeholder="Digite seu usuário" required autofocus>
          <svg class="tmr-field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
      </div>

      <div class="tmr-field">
        <label class="tmr-label" for="password">Senha</label>
        <div class="tmr-input-wrap">
          <input class="tmr-input" id="password" name="password" type="password" autocomplete="current-password" placeholder="Digite sua senha" required>
          <button class="tmr-password-toggle" type="button" id="toggle-password" aria-label="Mostrar senha" aria-pressed="false">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <button class="tmr-submit" type="submit">Entrar no painel</button>
    </form>

    <ul class="tmr-status" aria-label="Status da plataforma">
      <li>Worker 24/7</li>
      <li>Sessão persistente</li>
      <li>Roteamento inteligente</li>
    </ul>
  </section>
</main>
<script>
(() => {
  const input = document.getElementById('password');
  const button = document.getElementById('toggle-password');
  if (!input || !button) return;
  button.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', show ? 'true' : 'false');
    button.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
  });
})();
</script>
</body>
</html>
