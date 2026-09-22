<?php declare(strict_types=1);
/* Mobile/tablet bottom navigation; no routing or application logic changes. */
$path=__DIR__.'/index.php';
$html=@file_get_contents($path);
if (!is_string($html)) { fwrite(STDERR,"TMR_MOBILE_NAV_INDEX_MISSING\n"); exit(1); }
if (str_contains($html,'tmr-mobile-navigation')) { echo "TMR_MOBILE_NAV_ALREADY_APPLIED\n"; exit(0); }

$anchor='</header><main class="saas-content">';
if (substr_count($html,$anchor)!==1) { fwrite(STDERR,"TMR_MOBILE_NAV_INSERT_POINT_MISSING\n"); exit(1); }
$menu=<<<'HTML'
<nav class="tmr-mobile-navigation" aria-label="Navegação principal em celulares e tablets">
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
</nav>
HTML;

$html=str_replace($anchor,'</header>'.$menu.'<main class="saas-content">',$html);
// Load the approved bottom-navigation stylesheet with a new cache revision.
// Keep the responsive visibility/position as critical head CSS so a stale or
// delayed stylesheet can never reveal the old horizontal sidebar on mobile.
$css = <<<'HTML'
<link rel="stylesheet" href="/assets/brand/mobile-bottom-navigation.css?v=2">
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
</style>
HTML;
if (substr_count($html,'</head>')!==1) { fwrite(STDERR,"TMR_MOBILE_NAV_HEAD_MISSING\n"); exit(1); }
$html=str_replace('</head>',$css.'</head>',$html);
$temp=$path.'.mobile-nav-tmp';
if (@file_put_contents($temp,$html)===false) { fwrite(STDERR,"TMR_MOBILE_NAV_WRITE_FAILED\n"); exit(1); }
exec('php -l '.escapeshellarg($temp).' 2>&1',$lint,$exitCode);
if ($exitCode!==0) { @unlink($temp); fwrite(STDERR,"TMR_MOBILE_NAV_LINT_FAILED: ".implode(' ', $lint)."\n"); exit(1); }
if (!@rename($temp,$path)) { fwrite(STDERR,"TMR_MOBILE_NAV_RENAME_FAILED\n"); exit(1); }
echo "TMR_MOBILE_NAV_V1_APPLIED\n";
