<?php declare(strict_types=1);
putenv('TMR_NAV_TEST_ONLY=1');
$root=dirname(__DIR__);
$index=file_get_contents($root.'/index.php');
if(!is_string($index)||!preg_match('~<nav class="saas-nav">.*?</nav>~s',$index,$main))
    throw new RuntimeException('Main navigation is missing');
$pattern='~\bhref="([^"]+)"[^>]*>(<span class="nav-icon">.*?</span>.*?)</a>~s';
preg_match_all($pattern,$main[0],$sourceLinks,PREG_SET_ORDER);
$expected=[
 '/?page=dashboard','/?page=rules','/ai-learning.php',
 '/?page=events','/?page=integrations','/connect.php'
];
if(array_map(static fn($x)=>$x[1],$sourceLinks)!==$expected)
    throw new RuntimeException('Unexpected main menu destinations or order');
$old='<nav class="saas-nav">'
  .'<a href="/?page=dashboard"><span class="nav-icon">⌂</span>Visão geral</a>'
  .'<a href="/?page=rules"><span class="nav-icon">≋</span>Regras de roteamento</a>'
  .'<a href="/?page=events"><span class="nav-icon">◷</span>Atividade</a>'
  .'<a href="/?page=integrations"><span class="nav-icon">◇</span>Integrações</a>'
  .'<a href="/connect.php"><span class="nav-icon">♢</span>Telegram</a>'
  .'<a class="active" href="/reset.php"><span class="nav-icon">↺</span>Reset de dados</a>'
  .'</nav>';
$render=static function(string $nav) use ($root): string {
    $_SERVER['SCRIPT_FILENAME']='/tmp/reset.php';
    ob_start();
    require $root.'/runtime-ui-labels.php';
    echo '<!doctype html><html><head>'
        .'<link rel="stylesheet" href="/assets/saas.css">'
        .'<link rel="stylesheet" href="/assets/reset.css?v=6">'
        .'</head><body><div class="saas-shell"><aside class="saas-sidebar">'
        .'<div class="saas-brand">BRAND_UNCHANGED</div>'
        .$nav.'<div class="saas-bottom">BOTTOM_UNCHANGED</div>'
        .'</aside><div class="saas-main"><main class="saas-content reset-page">'
        .'RESET_FORM_UNCHANGED</main></div></div></body></html>';
    ob_end_flush();
    return (string)ob_get_clean();
};
$rendered=$render($old);
if(!preg_match('~<nav class="saas-nav">.*?</nav>~s',$rendered,$final))
    throw new RuntimeException('Reset navigation not found');
preg_match_all($pattern,$final[0],$actualLinks,PREG_SET_ORDER);
if(count($actualLinks)!==7||array_map(static fn($x)=>$x[1],array_slice($actualLinks,0,6))!==$expected
    ||$actualLinks[6][1]!=='/reset.php')
    throw new RuntimeException('Reset sidebar has wrong routes or ordering');
for($i=0;$i<6;$i++){
    if($actualLinks[$i][2]!==$sourceLinks[$i][2])
        throw new RuntimeException('Icon or label differs from main sidebar: '.$expected[$i]);
    if(!str_starts_with($actualLinks[$i][0],'<a href="'.$expected[$i].'">'))
        throw new RuntimeException('Inactive Reset link was not normalized: '.$expected[$i]);
}
if(!str_starts_with($actualLinks[6][0],'<a class="active" href="/reset.php">'))
    throw new RuntimeException('Reset link is not the only selected link');
if(!str_contains($actualLinks[6][2],'class="tmr-icon"')
    ||!str_contains($actualLinks[6][2],'stroke-width="1.7"')
    ||!str_contains($actualLinks[6][2],'width="20" height="20"'))
    throw new RuntimeException('Reset SVG uses a different icon style');
if(!str_contains($rendered,'BRAND_UNCHANGED')
    ||!str_contains($rendered,'BOTTOM_UNCHANGED')
    ||!str_contains($rendered,'RESET_FORM_UNCHANGED'))
    throw new RuntimeException('Sidebar parity changed content outside nav');
if(str_contains($rendered,'replaceWith(')||str_contains($rendered,'sessionStorage.getItem('))
    throw new RuntimeException('Reset sidebar still swaps menu after paint');
if(strpos($rendered,'id="telegramrouter-reset-sidebar-compat"')>strpos($rendered,'</head>'))
    throw new RuntimeException('Sidebar CSS added after paint');

// The same six canonical links must survive even if Reset has a misplaced
// previously inserted learning link. This is a real historical regression.
$legacy=str_replace('<a href="/?page=events">','<a href="/ai-learning.php">'
    .'<span class="nav-icon">✦</span>Aprendizado da IA</a><a href="/?page=events">',$old);
$repaired=$render($legacy);
if(substr_count($repaired,'href="/ai-learning.php"')!==1
    ||!str_contains($repaired,$final[0]))
    throw new RuntimeException('Legacy Reset sidebar did not normalize exactly once');
echo "SIDEBAR_CANONICAL_ICON_PARITY_ALL_ROUTES_PASSED\n";
