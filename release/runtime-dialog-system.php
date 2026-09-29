<?php declare(strict_types=1);

/**
 * Canonical project dialog installer.
 * Replaces browser-native destructive confirmations with the Telegram Router UI.
 */
$root=__DIR__;
$targets=[
    'index'=>$root.'/index.php',
    'reports'=>$root.'/reports.php',
    'connect'=>$root.'/connect.php',
    'reset'=>$root.'/reset.php',
];
foreach($targets as $label=>$path){
    if(!is_file($path)){fwrite(STDERR,"TMR_DIALOG_SOURCE_MISSING_".strtoupper($label)."\n");exit(1);}
}

$replaceOnce=static function(string $body,string $find,string $replace,string $label): string {
    if(str_contains($body,$replace)) return $body;
    if(substr_count($body,$find)!==1)throw new RuntimeException('TMR_DIALOG_ANCHOR_'.$label);
    return str_replace($find,$replace,$body);
};

try{
    $pages=[];
    foreach($targets as $key=>$path){
        $source=@file_get_contents($path);
        if(!is_string($source))throw new RuntimeException('TMR_DIALOG_READ_'.$key);
        $pages[$key]=$source;
    }

    if(!str_contains($pages['index'],'/assets/dialogs.css?v=3')){
        $pages['index']=$replaceOnce(
            $pages['index'],
            '<link rel="stylesheet" href="/assets/responsive.css?v=4">',
            '<link rel="stylesheet" href="/assets/responsive.css?v=4"><link rel="stylesheet" href="/assets/dialogs.css?v=3">',
            'INDEX_CSS'
        );
    }
    $pages['index']=str_replace(
        '<form method="post" class="routing-delete">',
        '<form method="post" class="routing-delete" data-dialog-confirm data-dialog-tone="danger">',
        $pages['index']
    );
    if(!str_contains($pages['index'],'/assets/dialogs.js?v=3')){
        $pages['index']=$replaceOnce(
            $pages['index'],
            '<script src="/assets/live.js" defer></script>',
            '<script src="/assets/dialogs.js?v=3" defer></script><script src="/assets/live.js" defer></script>',
            'INDEX_JS'
        );
    }

    if(!str_contains($pages['reports'],'/assets/dialogs.css?v=3')){
        $pages['reports']=$replaceOnce(
            $pages['reports'],
            '<link rel="stylesheet" href="/assets/reporting.css?v=6">',
            '<link rel="stylesheet" href="/assets/reporting.css?v=6">'."\n".'<link rel="stylesheet" href="/assets/dialogs.css?v=3">',
            'REPORTS_CSS'
        );
    }
    $pages['reports']=str_replace(
        'class="reporting-review-resolve-form" onsubmit="return confirm(\'Confirmar resultado manual desta seleção?\');"',
        'class="reporting-review-resolve-form" data-dialog-confirm data-dialog-tone="warning"',
        $pages['reports']
    );
    if(!str_contains($pages['reports'],'/assets/dialogs.js?v=3')){
        $pages['reports']=$replaceOnce(
            $pages['reports'],
            '<script src="/assets/live.js?v=2" defer></script>',
            '<script src="/assets/dialogs.js?v=3" defer></script>'."\n".'<script src="/assets/live.js?v=2" defer></script>',
            'REPORTS_JS'
        );
    }

    if(!str_contains($pages['connect'],'/assets/dialogs.css?v=3')){
        $pages['connect']=$replaceOnce(
            $pages['connect'],
            '</head>',
            '<link rel="stylesheet" href="/assets/dialogs.css?v=3"></head>',
            'CONNECT_CSS'
        );
    }
    $pages['connect']=str_replace(
        '<form method="post" class="disconnect-form">',
        '<form method="post" class="disconnect-form" data-dialog-confirm data-dialog-tone="danger">',
        $pages['connect']
    );
    $pages['connect']=str_replace('/assets/dialogs.js?v=2','/assets/dialogs.js?v=3',$pages['connect']);
    if(!str_contains($pages['connect'],'/assets/dialogs.js?v=3')){
        $pages['connect']=$replaceOnce(
            $pages['connect'],
            '</body>',
            '<script src="/assets/dialogs.js?v=3" defer></script></body>',
            'CONNECT_JS'
        );
    }

    if(!str_contains($pages['reset'],'/assets/dialogs.css?v=3')){
        $pages['reset']=$replaceOnce(
            $pages['reset'],
            '</head>',
            '<link rel="stylesheet" href="/assets/dialogs.css?v=3"></head>',
            'RESET_CSS'
        );
    }
    $pages['reset']=str_replace(
        "if(resetMode==='custom'){if(!categories.length){alert('Selecione pelo menos uma categoria para continuar.');return;}",
        "if(resetMode==='custom'){if(!categories.length){window.tmrDialog.alert({tone:'warning',title:'Selecione uma categoria',message:'Marque pelo menos uma categoria antes de revisar o reset personalizado.',confirmText:'Entendi'});return;}",
        $pages['reset']
    );
    if(!str_contains($pages['reset'],'/assets/dialogs.js?v=3')){
        $pages['reset']=$replaceOnce(
            $pages['reset'],
            "<script>(()=>{const dialog=document.getElementById('resetDialog')",
            '<script src="/assets/dialogs.js?v=3"></script>'."\n"."<script>(()=>{const dialog=document.getElementById('resetDialog')",
            'RESET_JS'
        );
    }

    foreach($pages as $key=>$source){
        $tmp=$targets[$key].'.dialog-candidate';
        if(@file_put_contents($tmp,$source)===false)throw new RuntimeException('TMR_DIALOG_WRITE_'.$key);
        $lint=[];$exit=0;
        exec('php -l '.escapeshellarg($tmp).' 2>&1',$lint,$exit);
        if($exit!==0)throw new RuntimeException('TMR_DIALOG_LINT_'.$key);
    }

    foreach($pages as $key=>$source){
        $tmp=$targets[$key].'.dialog-candidate';
        if(!@rename($tmp,$targets[$key]))throw new RuntimeException('TMR_DIALOG_APPLY_'.$key);
    }

    echo "TMR_PROJECT_DIALOGS_V3_APPLIED\n";
}catch(Throwable $e){
    foreach($targets as $path)@unlink($path.'.dialog-candidate');
    fwrite(STDERR,preg_replace('/[^A-Z0-9_]/','_',strtoupper(substr($e->getMessage(),0,180)))."\n");
    exit(1);
}
