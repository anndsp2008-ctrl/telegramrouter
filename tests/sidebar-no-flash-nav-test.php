<?php declare(strict_types=1);
putenv('TMR_NAV_TEST_ONLY=1');
$_SERVER['SCRIPT_FILENAME']='/tmp/reset.php';

function renderResetSidebar(string $nav): string {
    ob_start();
    require __DIR__.'/../runtime-ui-labels.php';
    echo '<!doctype html><html><head><link rel="stylesheet" href="/assets/reset.css?v=6"></head>'
        .'<body><div class="saas-shell"><aside class="saas-sidebar">'
        .$nav.'</aside><main id="app-content">UNALTERED_CONTENT</main></div></body></html>';
    ob_end_flush();
    return (string)ob_get_clean();
}
$links=[
    '/?page=dashboard'=>'⌂|Visão geral',
    '/?page=rules'=>'≋|Regras de roteamento',
    '/?page=events'=>'◷|Atividade',
    '/?page=integrations'=>'◇|Integrações',
    '/connect.php'=>'♢|Telegram',
    '/reset.php'=>'↺|Reset de dados',
];
$nav='<nav class="saas-nav">';
foreach($links as $url=>$label){
    [$icon,$name]=explode('|',$label);
    $active=$url==='/reset.php'?' class="active"':'';
    $nav.='<a'.$active.' href="'.$url.'"><span class="nav-icon">'.$icon.'</span>'.$name.'</a>';
}
$nav.='</nav>';
$out=renderResetSidebar($nav);
if(substr_count($out,'href="/ai-learning.php"')!==1
    || strpos($out,'href="/?page=rules"')>strpos($out,'href="/ai-learning.php"')
    || strpos($out,'href="/ai-learning.php"')>strpos($out,'href="/?page=events"'))
    throw new RuntimeException('Learning menu not directly below routing rules');
if(preg_match('~href="/\?page=rules".*?</a>\s*<a\b[^>]*href="/ai-learning\.php"~s',$out)!==1)
    throw new RuntimeException('Learning link is not directly after routing rules');
if(str_contains($out,'telegramrouter-reset-sidebar-sync')||str_contains($out,'replaceWith(')
    ||str_contains($out,'sessionStorage.getItem('))
    throw new RuntimeException('Post-paint sidebar replacement still present');
if(substr_count($out,'class="nav-icon-svg"')!==7)
    throw new RuntimeException('Sidebar icons are not rendered before paint');
if(strpos($out,'id="telegramrouter-reset-sidebar-compat"')>strpos($out,'</head>'))
    throw new RuntimeException('Sidebar style loaded after first paint');
if(!str_contains($out,'<main id="app-content">UNALTERED_CONTENT</main>')
    ||!str_contains($out,'<a class="active" href="/reset.php">'))
    throw new RuntimeException('Reset page content/selection changed');

// A reset page with a pre-existing, misplaced learning link is normalized
// once, without modifying the rest of the navigation.
$oldLearn='<a href="/ai-learning.php"><span class="nav-icon">✦</span>Aprendizado da IA</a>';
$beforeEvents='<a href="/?page=events">';
$incorrect=str_replace($beforeEvents,$oldLearn.$beforeEvents,$nav);
$again=renderResetSidebar($incorrect);
if(substr_count($again,'href="/ai-learning.php"')!==1
    ||preg_match('~href="/\?page=rules".*?</a>\s*<a\b[^>]*href="/ai-learning\.php"~s',$again)!==1)
    throw new RuntimeException('Navigation normalization duplicated or misordered the link');

// Index branch remains intact: only its existing cache snapshot is written;
// no sidebar DOM replacement, menu reordering or browser navigation added.
$_SERVER['SCRIPT_FILENAME']='/tmp/index.php';
ob_start();
require __DIR__.'/../runtime-ui-labels.php';
echo '<!doctype html><html><head></head><body><aside class="saas-sidebar">'
    .'<nav class="saas-nav"><a href="/?page=rules">Regras de roteamento</a>'
    .$oldLearn.'</nav></aside><main>UNALTERED_INDEX</main></body></html>';
ob_end_flush();
$index=(string)ob_get_clean();
if(!str_contains($index,'UNALTERED_INDEX')||!str_contains($index,'telegramrouter-sidebar-sync')
    ||str_contains($index,'replaceWith('))
    throw new RuntimeException('Index regression');
echo "SIDEBAR_NO_FLASH_NAV_REGRESSION_PASSED\n";
