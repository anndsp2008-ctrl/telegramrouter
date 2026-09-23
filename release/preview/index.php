<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Telegram Media Router · Preview</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header>
  <div><strong>Telegram Media Router</strong><span class="muted">Preview público</span></div>
  <button class="secondary">Painel protegido</button>
</header>
<main>
  <div class="preview-note"><b>Modo demonstração.</b> Os dados abaixo são fictícios; nenhuma conta Telegram ou credencial está conectada.</div>
  <nav><a class="active" href="#dashboard">Visão geral</a><a href="#rules">Regras</a><a href="#credentials">Credenciais</a><a href="#events">Eventos</a></nav>
  <section class="stats">
    <div class="stat"><b>4</b><span>Regras</span></div>
    <div class="stat"><b>3</b><span>Ativas</span></div>
    <div class="stat"><b>127</b><span>Encaminhadas hoje</span></div>
    <div class="stat"><b class="ok">Conectado</b><span>Worker Telegram</span></div>
  </section>
  <section class="grid two" id="rules">
    <div class="card"><h2>Atividade recente</h2>
      <article class="event"><span class="dot green"></span><div><b>Mensagem encaminhada</b><p class="muted">@noticias_origem → @canal_publicado · há 2 min</p></div><span class="badge forwarded">forwarded</span></article>
      <article class="event"><span class="dot green"></span><div><b>Mensagem encaminhada</b><p class="muted">@ofertas_diarias → @meu_canal · há 8 min</p></div><span class="badge forwarded">forwarded</span></article>
      <article class="event"><span class="dot amber"></span><div><b>Sem foto anterior válida</b><p class="muted">@esportes_live → @resumo_esportes · há 14 min</p></div><span class="badge skipped">skipped</span></article>
      <article class="event"><span class="dot red"></span><div><b>Falha temporária de tradução</b><p class="muted">@news_global → @news_pt · há 21 min</p></div><span class="badge failed">failed</span></article>
    </div>
    <div class="card"><h2>Regras configuradas</h2>
      <article class="item"><div><b>@noticias_origem</b> → <b>@canal_publicado</b><p class="muted">Gatilho: Breaking · Foto anterior · links removidos</p></div><span class="status-on">Ativa</span></article>
      <article class="item"><div><b>@ofertas_diarias</b> → <b>@meu_canal</b><p class="muted">Gatilho: OFERTA · Mídia atual · emojis removidos</p></div><span class="status-on">Ativa</span></article>
      <article class="item"><div><b>@news_global</b> → <b>@news_pt</b><p class="muted">Gatilho: News · Somente texto · Gemini</p></div><span class="status-on">Ativa</span></article>
      <article class="item"><div><b>@esportes_live</b> → <b>@resumo_esportes</b><p class="muted">Gatilho: Resultado · Foto anterior</p></div><span class="status-off">Pausada</span></article>
    </div>
  </section>
  <section class="card" id="credentials"><h2>Fluxo de credenciais</h2><p class="muted">Na versão instalada, esta área permite configurar API ID, Chave da API, StringSession e ID do usuário Telegram. Os dados são cifrados antes de serem persistidos.</p><div class="credential-grid"><div><span class="label">API ID</span><strong>Configurado</strong></div><div><span class="label">Chave da API</span><strong>Configurado</strong></div><div><span class="label">StringSession</span><strong>Configurada</strong></div><div><span class="label">Sessão</span><strong class="ok">Protegida</strong></div></div></section>
  <section class="card" id="events"><h2>Objetivo do sistema</h2><p>Monitorar mensagens de chats do Telegram, aplicar transformações como remoção de links, remoção de emojis e tradução, e encaminhar texto ou mídia para destinos configurados por regras.</p><div class="flow"><span>Mensagem recebida</span><i>→</i><span>Regra encontrada</span><i>→</i><span>Texto transformado</span><i>→</i><span>Destino publicado</span></div></section>
</main>
</body>
</html>
<style>
.preview-note{margin-bottom:18px;padding:12px 14px;border:1px solid #76f3e455;border-radius:10px;background:#76f3e412;color:#a9fff5}.ok{color:#83f2b5!important}.event{display:flex;align-items:center;gap:12px;padding:15px 0;border-top:1px solid #ffffff12}.event>div{flex:1}.event p{margin:3px 0 0}.dot{width:8px;height:8px;border-radius:50%;flex:none}.green{background:#75efaf}.amber{background:#ffd06f}.red{background:#ff7b86}.status-on,.status-off{font-size:12px;padding:5px 9px;border-radius:99px}.status-on{color:#83f2b5;background:#39d98a22}.status-off{color:#9aaab2;background:#ffffff10}.credential-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:18px}.credential-grid>div{padding:14px;border-radius:10px;background:#081017;border:1px solid #ffffff12}.label{display:block;color:#8da0ab;font-size:12px;margin-bottom:6px}.flow{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:18px}.flow span{padding:10px 13px;border-radius:9px;background:#76f3e412;color:#b8fff8;border:1px solid #76f3e42b}.flow i{color:#76f3e4;font-style:normal}@media(max-width:700px){.credential-grid{grid-template-columns:1fr 1fr}}
</style>
<script>document.querySelectorAll('nav a').forEach(a=>a.addEventListener('click',e=>{document.querySelectorAll('nav a').forEach(x=>x.classList.remove('active'));e.currentTarget.classList.add('active')}));</script>
