<?php declare(strict_types=1);
/* Read-only checks of the post-startup index and Reset HTML templates. */
$indexPath=$argv[1]??'';
$resetPath=$argv[2]??'';
$index=$indexPath!==''?file_get_contents($indexPath):false;
$reset=$resetPath!==''?file_get_contents($resetPath):false;
if(!is_string($index)||!is_string($reset))throw new RuntimeException('Mobile header/nav fixture absent');
preg_match('~<nav class="tmr-mobile-navigation"[\s\S]*?</nav>~',$index,$mainNav);
preg_match('~<nav class="tmr-mobile-navigation"[\s\S]*?</nav>~',$reset,$resetNav);
$main=(string)($mainNav[0]??'');
$mobile=(string)($resetNav[0]??'');
$mainStatic=preg_replace('~<\?=[\s\S]*?\?>~','',$main,-1,$expressions);
$resetNormalized=str_replace([
    '<a class="is-active" aria-current="page" href="/reset.php">',
    '<details class="tmr-nav-more is-active">'
],[
    '<a href="/reset.php">',
    '<details class="tmr-nav-more">'
],$mobile);
$indexHead=explode('</head>',$index,2)[0]??'';
$resetHead=explode('</head>',$reset,2)[0]??'';
$brandCss='mobile-shell.css?v=2';
$navCss='mobile-bottom-navigation.css?v=2';
$headerCss='mobile-app-header.css?v=2';
$checks=[
    'same approved mobile navigation' => $main!==''&&$mobile!==''&&$expressions===8&&$mainStatic===$resetNormalized,
    'only one mobile nav on Reset' => substr_count($reset,'<nav class="tmr-mobile-navigation"')===1,
    'Reset selected inside More' => str_contains($mobile,'<details class="tmr-nav-more is-active">')
        && substr_count($mobile,'<a class="is-active" aria-current="page" href="/reset.php">')===1,
    'no undefined index state in Reset' => !str_contains($mobile,'<?')&&!str_contains($mobile,'$active'),
    'nav belongs below header' => str_contains($reset,'</header>'.$mobile.'<main class="saas-content reset-page">'),
    'mobile sidebar hidden before paint' => str_contains($resetHead,'id="tmr-mobile-bottom-nav-critical"')
        && str_contains($resetHead,'.saas-shell>.saas-sidebar{display:none!important}'),
    'same bottom bar stylesheet' => substr_count($resetHead,$navCss)===1
        && substr_count($indexHead,$navCss)===1,
    'mobile header CSS overrides shared shell CSS' => strpos($resetHead,$brandCss)!==false
        && strpos($resetHead,$brandCss)<strpos($resetHead,$headerCss)
        && strpos($resetHead,$navCss)<strpos($resetHead,$headerCss),
    'logout style after shared shell' => strpos($resetHead,'logout-icon.css?v=1')>strpos($resetHead,$brandCss),
    'index and Reset load responsive base' => str_contains($indexHead,'responsive.css?v=4')
        && str_contains($resetHead,'responsive.css?v=4'),
    'header retains canonical user row' => substr_count($reset,'class="tmr-app-header-brand"')===1
        && str_contains($reset,'rh($resetTopbarGreeting)')
        && str_contains($reset,'class="connected-phone"'),
    'reset operations remain unchanged' => str_contains($reset,'id="resetExecuteForm"')
        && str_contains($reset,'name="security_phrase"')
        && str_contains($reset,'name="action" value="execute_reset"')
        && str_contains($reset,"if (\$action==='execute_reset')")
];
foreach($checks as $name=>$ok){
    if(!$ok)throw new RuntimeException('Reset mobile parity regression: '.$name);
}
echo "RESET_MOBILE_BOTTOM_NAV_PARITY_TESTS_PASSED\n";
