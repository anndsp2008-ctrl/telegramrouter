<?php declare(strict_types=1);

function tmrThemePatch(string $file,bool $withToggle): void {
    if(!is_file($file)) return;
    $html=(string)file_get_contents($file);
    if(!str_contains($html,'/assets/brand/theme.js?v=1')){
        if(str_contains($html,'</title>')) $html=str_replace('</title>','</title><script src="/assets/brand/theme.js?v=1"></script>',$html);
        elseif(str_contains($html,'<head>')) $html=str_replace('<head>','<head><script src="/assets/brand/theme.js?v=1"></script>',$html);
    }
    $html=str_replace(['/assets/brand/theme.css?v=1','/assets/brand/theme.css?v=2'],'/assets/brand/theme.css?v=7',$html);
    if(!str_contains($html,'/assets/brand/theme.css?v=7')&&str_contains($html,'</head>')){
        $html=str_replace('</head>','<link rel="stylesheet" href="/assets/brand/theme.css?v=7"></head>',$html);
    }
    if($withToggle&&!str_contains($html,'theme-toggle.php')){
        $actionPos=strpos($html,'name="action" value="logout"');
        if($actionPos!==false){
            $formPos=strrpos(substr($html,0,$actionPos),'<form');
            if($formPos!==false){
                $include='<?php require __DIR__.\'/app/theme-toggle.php\'; ?>';
                $html=substr($html,0,$formPos).$include.substr($html,$formPos);
            }
        }
    }
    file_put_contents($file,$html);
}
foreach(['index.php','reports.php','ai-learning.php','reset.php'] as $page) tmrThemePatch(__DIR__.'/'.$page,true);
tmrThemePatch(__DIR__.'/connect.php',false);
echo "TMR_THEME_RUNTIME_APPLIED\n";
