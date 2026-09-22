<?php declare(strict_types=1);
/* Tests the post-startup Reset header; no DB, Telegram or destructive action. */
$path=$argv[1]??'';
$source=$path!==''?file_get_contents($path):false;
if(!is_string($source))throw new RuntimeException('Reset runtime fixture missing');
preg_match('~<header class="saas-topbar">.*?</header>~s',$source,$match);
$header=$match[0]??'';
$checks=[
    'canonical app brand' => substr_count($header,'class="tmr-app-header-brand"')===1,
    'canonical avatar' => str_contains($header,'<span class="saas-avatar">AN</span>'),
    'same greeting pattern' => str_contains($header,'rh($resetTopbarGreeting)'),
    'connected Telegram indicator' => str_contains($header,'class="connected-phone"'),
    'one logout button' => substr_count($header,'class="saas-logout"')===1,
    'logout action delegated to existing index' => str_contains($header,'<form method="post" action="/">'),
    'CSRF-protected logout' => str_contains($header,'name="csrf" value="<?=rh(Auth::csrf())?>"')
        && str_contains($header,'name="action" value="logout"'),
    'same logout SVG' => str_contains($header,'<svg viewBox="0 0 24 24"'),
    'mobile header stylesheet' => str_contains($source,'/assets/brand/mobile-app-header.css?v=2'),
    'logout icon stylesheet' => str_contains($source,'/assets/brand/logout-icon.css?v=1'),
    'old reset-only header removed' => !str_contains($header,'<span class="saas-avatar">AD</span>'),
    'reset controls unchanged' => str_contains($source,'id="resetExecuteForm"')
        && str_contains($source,"if ($action==='execute_reset')") 
        && str_contains($source,'name="security_phrase"'),
];
foreach($checks as $name=>$passed){
    if(!$passed)throw new RuntimeException('Reset header parity regression: '.$name);
}
echo "RESET_CANONICAL_TOPBAR_TESTS_PASSED\n";
