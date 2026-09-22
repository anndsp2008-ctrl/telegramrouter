<?php declare(strict_types=1);

$css=(string)file_get_contents(__DIR__.'/../assets/brand/connect-responsive.css');

foreach([
    '@media (min-width:641px) and (max-width:1024px)',
    '.form-intro>a[href*="page=rules"]',
    '.disconnect-form',
    'display:inline-flex!important',
    'width:206px!important',
    'min-width:206px!important',
    'min-height:48px!important',
    'margin:18px 0 0 12px!important'
] as $anchor){
    if(!str_contains($css,$anchor)){
        throw new RuntimeException('Tablet connect action anchor missing: '.$anchor);
    }
}

$mobile=strpos($css,'@media (max-width:640px)');
if($mobile===false){
    throw new RuntimeException('Mobile breakpoint missing');
}
$mobileBlock=substr($css,$mobile);
if(!str_contains($mobileBlock,'.form-intro>a[href*="page=rules"],')
   || !str_contains($mobileBlock,'.disconnect-button{')
   || !str_contains($mobileBlock,'width:100%!important')){
    throw new RuntimeException('Mobile full-width actions regression');
}

echo "CONNECT_TABLET_ACTIONS_TESTS_PASSED\n";
