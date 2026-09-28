<?php
$navActive = isset($navActive) ? (string)$navActive : '';
?>
<nav class="tmr-mobile-navigation" aria-label="Navegação principal em celulares e tablets">
  <a href="/?page=dashboard" class="<?=($navActive==='dashboard'?'is-active':'')?>" <?=($navActive==='dashboard'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg></span><span>Visão geral</span></a>
  <a href="/?page=rules" class="<?=($navActive==='rules'?'is-active':'')?>" <?=($navActive==='rules'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01" stroke-width="3"/></svg></span><span>Regras</span></a>
  <a class="tmr-nav-create" href="/?page=rules#nova-regra" aria-label="Criar nova regra"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span><span>Nova regra</span></a>
  <a href="/?page=events" class="<?=($navActive==='events'?'is-active':'')?>" <?=($navActive==='events'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></span><span>Atividade</span></a>
  <details class="tmr-nav-more">
    <summary aria-label="Mais módulos"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg></span><span>Mais</span></summary>
    <div class="tmr-more-panel">
      <a href="/reports.php" class="<?=($navActive==='reports'?'is-active':'')?>" <?=($navActive==='reports'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/></svg></span><span>Relatórios</span></a>
      <a href="/?page=integrations" class="<?=($navActive==='integrations'?'is-active':'')?>" <?=($navActive==='integrations'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3v6M16 3v6M6 9h12v3a6 6 0 0 1-12 0V9zM12 18v3"/></svg></span><span>Integrações</span></a>
      <a href="/connect.php" class="<?=($navActive==='telegram'?'is-active':'')?>" <?=($navActive==='telegram'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 7a7 7 0 0 1 10 0M4 4a11 11 0 0 1 16 0M10 10a3 3 0 0 1 4 0"/><circle cx="12" cy="15" r="1.5"/></svg></span><span>Conectar Telegram</span></a>
      <a href="/ai-learning.php" class="<?=($navActive==='ai-learning'?'is-active':'')?>" <?=($navActive==='ai-learning'?'aria-current="page"':'')?>><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3l1.8 6.2L20 11l-6.2 1.8L12 19l-1.8-6.2L4 11l6.2-1.8L12 3z"/><path d="M19 17v4M17 19h4"/></svg></span><span>Aprendizado da IA</span></a>
      <a href="/reset.php"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v6h6"/><path d="M12 8v4M12 16h.01"/></svg></span><span>Reset de dados</span></a>
    </div>
  </details>
</nav>
