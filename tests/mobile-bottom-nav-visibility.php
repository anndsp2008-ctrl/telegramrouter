<?php declare(strict_types=1);
/* Read-only check of the rebuilt index used by the production runtime. */
$path=$argv[1]??'';
$html=$path!==''?file_get_contents($path):false;
if(!is_string($html))throw new RuntimeException('Rebuilt index not available');
$head=explode('</head>',$html,2)[0]??'';
$checks=[
    'one approved bottom navigation' =>
        substr_count($html,'<nav class="tmr-mobile-navigation"')===1,
    'cached stylesheet refreshed in head' =>
        substr_count($head,'/assets/brand/mobile-bottom-navigation.css?v=2')===1,
    'critical responsive style in head' =>
        substr_count($head,'id="tmr-mobile-bottom-nav-critical"')===1,
    'hide old top navigation on mobile and tablet' =>
        preg_match('/@media\s*\(max-width:1024px\)\s*\{[\s\S]*?\.saas-shell>\.saas-sidebar\s*\{display:none!important\}/',$head)===1,
    'show bottom navigation on mobile and tablet' =>
        str_contains($head,'nav.tmr-mobile-navigation{')
        && str_contains($head,'display:grid!important;position:fixed!important'),
    'keep desktop bottom navigation hidden' =>
        str_contains($head,'@media (min-width:1025px){nav.tmr-mobile-navigation{display:none!important}}'),
    'five-item approved bottom navigation unchanged' =>
        str_contains($html,'class="tmr-nav-create"')
        && str_contains($html,'class="tmr-nav-more"')
        && str_contains($html,'aria-label="Navegação principal em celulares e tablets"'),
    'AI Learning and Reset are present only in the More panel' =>
        preg_match('~<details class="tmr-nav-more">[\\s\\S]*?<div class="tmr-more-panel">([\\s\\S]*?)</div>~',$html,$more)===1
        && substr_count($more[1]??'','href="/ai-learning.php"')===1
        && substr_count($more[1]??'','href="/reset.php"')===1
        && substr_count($more[1]??'','href="/connect.php"')===1
        && substr_count($more[1]??'','href="/?page=integrations"')===1
        && strpos($more[1],'href="/ai-learning.php"') > strpos($more[1],'href="/connect.php"')
        && strpos($more[1],'href="/reset.php"') > strpos($more[1],'href="/ai-learning.php"')
        && substr_count($html,'href="/ai-learning.php"')===1
        && substr_count($html,'href="/reset.php"')===1,
    'main panel content still present' =>
        str_contains($html,'<main class="saas-content">'),
];
foreach($checks as $name=>$passed){
    if(!$passed)throw new RuntimeException('Mobile navigation regression: '.$name);
}
$source=file_get_contents(__DIR__.'/../assets/brand/mobile-bottom-navigation.css');
if(!is_string($source)
    || !str_contains($source,'.saas-sidebar .saas-nav{display:none!important}')
    || !str_contains($source,'.tmr-mobile-navigation{')
    || !str_contains($source,'@media (max-width:1024px)'))
    throw new RuntimeException('Approved mobile navigation stylesheet changed unexpectedly');
echo "MOBILE_BOTTOM_NAV_VISIBILITY_TESTS_PASSED\n";
