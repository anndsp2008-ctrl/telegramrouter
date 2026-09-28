<?php
$navActive = isset($navActive) ? (string)$navActive : '';
?>
<aside class="saas-sidebar">
  <div class="saas-brand">
    <span class="saas-logo brand-mark"><img src="/assets/brand/mark.svg?v=1" alt="" aria-hidden="true"></span>
    <div><b>Telegram Router</b><small>Automação inteligente</small></div>
  </div>
  <div class="saas-nav-label">PAINEL</div>
  <nav class="saas-nav">
    <a class="<?= $navActive==='dashboard'?'active':'' ?>" href="/?page=dashboard"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/></svg></span>Visão geral</a>
    <a class="<?= $navActive==='rules'?'active':'' ?>" href="/?page=rules"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v10a2 2 0 0 0 2 2h4M18 17V7a2 2 0 0 0-2-2h-4m-2 12 2 2-2 2m4-18-2 2 2 2"/></svg></span>Regras de roteamento</a>
    <a class="<?= $navActive==='ai-learning'?'active':'' ?>" href="/ai-learning.php"><span class="nav-icon">✦</span>Aprendizado da IA</a>
    <a class="<?= $navActive==='events'?'active':'' ?>" href="/?page=events"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>Atividade</a>
    <a class="<?= $navActive==='integrations'?'active':'' ?>" href="/?page=integrations"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 3v5m6-5v5M7 8h10v4a5 5 0 0 1-10 0ZM12 17v4"/></svg></span>Integrações</a>
    <a class="<?= $navActive==='telegram'?'active':'' ?>" href="/connect.php"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m21 3-7 18-4-7-7-4Zm0 0L10 14"/></svg></span>Telegram</a>
    <a class="<?= $navActive==='reports'?'active':'' ?>" href="/reports.php"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/></svg></span>Relatórios</a>
    <a class="<?= $navActive==='reset'?'active':'' ?>" href="/reset.php"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v6h6"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span>Reset de dados</a>
  </nav>
  <div class="saas-bottom"><div class="saas-status"><i></i>Serviço protegido</div></div>
</aside>
