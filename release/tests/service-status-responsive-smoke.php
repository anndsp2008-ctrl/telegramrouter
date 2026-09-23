<?php declare(strict_types=1);

$css=(string)file_get_contents(__DIR__.'/../assets/brand/service-status-responsive.css');
$brand=(string)file_get_contents(__DIR__.'/../runtime-brand.php');

foreach([
    '@media (max-width:1024px)',
    '@media (max-width:640px)',
    '.overview-status{',
    'grid-template-areas:',
    '"icon main"',
    '"meta meta"',
    '.overview-status>.overview-status-meta',
    'grid-template-areas:',
    '"label action"',
    '"value action"',
    'min-height:44px',
    'border-top:1px solid',
    'overflow-wrap:anywhere'
] as $anchor){
    if(!str_contains($css,$anchor)){
        throw new RuntimeException('Responsive service-card anchor missing: '.$anchor);
    }
}

if(!str_contains($brand,'service-status-responsive.css?v=1')){
    throw new RuntimeException('Responsive service-card stylesheet is not loaded by runtime-brand');
}

$firstRulePos=strpos($css,'.overview-status{');
$tabletPos=strpos($css,'@media (max-width:1024px)');
if($firstRulePos===false||$tabletPos===false||$firstRulePos<$tabletPos){
    throw new RuntimeException('Service-card rules leaked into desktop layout');
}

if(!str_contains($css,'@media (max-width:380px)')){
    throw new RuntimeException('Narrow mobile guard missing');
}

echo "SERVICE_STATUS_RESPONSIVE_TESTS_PASSED\n";
