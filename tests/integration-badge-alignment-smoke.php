<?php declare(strict_types=1);
/**
 * Regression: the configured/unconfigured pill remains on the RIGHT at every
 * viewport; the page's other integration statuses are not repositioned.
 */
$root=dirname(__DIR__);
$mobile=(string)file_get_contents($root.'/assets/brand/mobile-visual-audit.css');
$desktop=(string)file_get_contents($root.'/assets/brand/integrations-v10.css');
$installer=(string)file_get_contents($root.'/runtime-brand.php');
$fail=static function(string $message): never {throw new RuntimeException($message);};
$required=[
    ['desktop placement', $desktop, 'justify-self:end!important;'],
    ['mobile base visibility', $desktop, 'display:inline-flex!important;'],
    ['mobile column alignment', $desktop, 'grid-column:2 / 4!important;'],
    ['mobile title-chevron row', $desktop, 'grid-row:1!important;'],
    ['mobile/touch badge', $mobile, 'justify-self:end!important;'],
    ['tiny phone alignment', $mobile, 'grid-column:2 / 4!important;'],
    ['tablet right margin', $mobile, 'margin:0 0 0 auto!important;'],
    ['stylesheet cache and Railway startup compatibility', $installer, '/assets/brand/integrations-v10.css?v=8&badge-compact=2'],
    ['responsive cache v11', $installer, '/assets/brand/mobile-visual-audit.css?v=12']
];
foreach($required as [$label,$source,$token]){
    if(!str_contains($source,$token))$fail('Integration badge alignment regression: '.$label);
}
if(str_contains($desktop,'.translation-provider-grid .provider-badge{'."\n".'    display:none!important;'))
    $fail('Provider badge is hidden at a mobile breakpoint');
// Inspect only the LAST responsive override: legacy rules above intentionally
// differ, but the final override must keep badge and chevron in row 1.
$start=strrpos($mobile,'/* Integration card invariant:');
if($start===false)$fail('Missing final cross-breakpoint same-row layout');
$invariant=substr($mobile,$start);
foreach([
    '@media (max-width:1024px)',
    '@media (max-width:400px)',
    'display:grid!important;',
    'grid-template-columns:40px minmax(0,1fr) max-content 34px!important;',
    'grid-template-columns:36px minmax(0,1fr) max-content 32px!important;',
    'grid-column:3!important;grid-row:1!important;',
    'grid-column:4!important;grid-row:1!important;',
    'grid-column:1 / -1!important;grid-row:2!important;',
    'white-space:nowrap!important;'
] as $requiredToken){
    if(!str_contains($invariant,$requiredToken))
        $fail('Same-row status/chevron invariant missing: '.$requiredToken);
}
// The badge and chevron must be placed in adjacent cells of one header row,
// never on separate rows or in a mobile full-width badge row.
if(preg_match('/> \.provider-badge\s*\{[^}]*grid-row\s*:\s*2\s*!important/s',$invariant) ||
   preg_match('/> \.provider-badge\s*\{[^}]*grid-column\s*:\s*2\s*\/\s*4\s*!important/s',$invariant)){
    $fail('Mobile badge would leave the chevron row');
}
echo "INTEGRATION_BADGE_SAME_ROW_ALL_VIEWPORTS_TESTS_PASSED\n";

// Regression for the screenshot: the first three provider pills were
// stretched across the entire header, while the final provider was compact.
// Apply the same intrinsic-size contract to ALL provider cards, irrespective
// of which input or form the provider happens to contain.
$contractStart=strrpos($mobile,'/* FINAL provider badge sizing:');
if($contractStart===false)$fail('Missing final compact badge override');
$contract=substr($mobile,$contractStart);
$globalStart=strrpos($desktop,'/* Global status badge size contract');
if($globalStart===false)$fail('Missing global provider badge width invariant');
$global=substr($desktop,$globalStart);
foreach([$contract,$global] as $scope){
    foreach([
        '.translation-provider-grid > * > :is(.provider-head,.saas-card-head)>.provider-badge',
        'width:max-content!important;',
        'inline-size:max-content!important;',
        'min-width:max-content!important;',
        'max-width:none!important;',
        'flex:0 0 auto!important;',
        'justify-self:end!important;'
    ] as $token){
        if(!str_contains($scope,$token))$fail('Provider badge expands or varies across cards: '.$token);
    }
    if(str_contains($scope,'width:100%!important;') ||
       str_contains($scope,'grid-column:2 / 4!important;') ||
       str_contains($scope,'justify-self:stretch!important;'))
        $fail('Provider badge may stretch across header');
}
foreach([
    'grid-column:3 / 4!important;',
    'grid-row:1 / 2!important;',
    'grid-column:4!important;grid-row:1!important;'
] as $token){
    if(!str_contains($contract,$token))$fail('Badge and chevron no longer share header row: '.$token);
}
echo "INTEGRATION_BADGE_COMPACT_ALL_PROVIDERS_TESTS_PASSED\n";
