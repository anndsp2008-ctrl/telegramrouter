<?php declare(strict_types=1);
/* Mobile/tablet app header: preserve original topbar content and desktop layout. */
$path=__DIR__.'/index.php';
$source=@file_get_contents($path);
if(!is_string($source)){fwrite(STDERR,"TMR_APP_HEADER_INDEX_MISSING\n");exit(1);}
$brand=<<<'HTML'
<div class="tmr-app-header-brand" aria-label="TelegramRouter">
  <span class="tmr-app-brand-symbol"><img src="/assets/brand/mark.svg?v=1" alt=""></span>
  <span class="tmr-app-brand-name"><b>Telegram<span>Router</span></b><small>Conecte. Direcione. Automatize.</small></span>
</div>
HTML;
if (!str_contains($source,'class="tmr-app-header-brand"')) {
    $start='<header class="saas-topbar"><div class="saas-user">';
    if (substr_count($source,$start)!==1){fwrite(STDERR,"TMR_APP_HEADER_ANCHOR_MISSING\n");exit(1);}
    $source=str_replace($start,'<header class="saas-topbar">'.$brand.'<div class="saas-user">',$source);
}
$stylesheet='<link rel="stylesheet" href="/assets/brand/mobile-app-header.css?v=1">';
if (!str_contains($source,'mobile-app-header.css')) {
    if(substr_count($source,'</head>')!==1){fwrite(STDERR,"TMR_APP_HEADER_HEAD_MISSING\n");exit(1);}
    $source=str_replace('</head>',$stylesheet.'</head>',$source);
}
$tmp=$path.'.app-header-candidate';
if(@file_put_contents($tmp,$source)===false){fwrite(STDERR,"TMR_APP_HEADER_WRITE_FAILED\n");exit(1);}
$lint=[];$exitCode=0;
exec('php -l '.escapeshellarg($tmp).' 2>&1',$lint,$exitCode);
if($exitCode!==0){@unlink($tmp);fwrite(STDERR,"TMR_APP_HEADER_LINT_FAILED ".implode(" ",$lint)."\n");exit(1);}
if(!@rename($tmp,$path)){fwrite(STDERR,"TMR_APP_HEADER_REPLACE_FAILED\n");exit(1);}
echo "TMR_APP_HEADER_V1_APPLIED\n";
