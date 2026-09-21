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
    ['stylesheet cache and Railway startup compatibility', $installer, '/assets/brand/integrations-v10.css?v=8&workers-dot=5'],
    ['responsive cache v11', $installer, '/assets/brand/mobile-visual-audit.css?v=14']
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
        '.provider-badge',
        'width:max-content!important;',
        'inline-size:max-content!important;',
        'min-width:max-content!important;',
        'max-width:none!important;',
        'flex:0 0 auto!important;',
        'justify-self:end!important;'
    ] as $token){
        if(!str_contains($scope,$token))$fail('Provider badge expands or varies across cards: '.$token);
    }
    if(preg_match('/(?<![a-z-])width:100%\\s*!important;/i',$scope) ||
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

// Production runtime inspection confirmed all three existing providers use:
// <span class="form-status STATE"><i></i>LABEL</span>
// Workers AI must use that exact visual contract and inherit the same shared
// CSS; it must not carry provider-badge or Workers-specific form-status CSS.
$workers=(string)file_get_contents($root.'/app/workers-ai-card.php');
$workerCss=(string)file_get_contents($root.'/assets/brand/workers-ai-provider.css');
if(!str_contains($workers,'class="workers-ai-observation"') ||
   substr_count($workers,'Se o Llama 3.2 Vision retornar uma imagem')!==1)
   $fail('Workers AI explanatory content changed unexpectedly');
foreach([
  '<span class="form-status <?=$workersAIConfigured?\'is-configured\':\'is-empty\'?>"><i></i>',
  "\$workersAIConfigured?'Configurado':'Não configurado'"
] as $token){
  if(!str_contains($workers,$token))$fail('Workers AI status markup differs from Google/Azure/Gemini: '.$token);
}
if(str_contains($workers,'provider-badge'))
  $fail('Workers AI must not use provider-badge markup');
if(str_contains($workerCss,'> .form-status{') ||
   str_contains($workerCss,'> .form-status {') ||
   str_contains($workerCss,'@media(max-width:640px)'))
  $fail('Workers AI has provider-specific form-status styling instead of shared styles');
if(!str_contains($desktop,'.translation-provider-grid .form-status') ||
   !str_contains($mobile,':is(.provider-badge,.form-status)'))
  $fail('Shared form-status positioning/display rules are missing');
echo "INTEGRATION_WORKERS_EXACT_LIVE_FORM_STATUS_PARITY_TESTS_PASSED\n";
