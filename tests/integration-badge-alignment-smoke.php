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

// Real fourth provider is a form-status span, not just a generic provider badge.
// The responsive override MUST win over its legacy mobile "row 2 / col 2"
// placement, and its explanatory note must remain in the same text wrapper
// as the ordinary title and description.
$workers=(string)file_get_contents($root.'/app/workers-ai-card.php');
$workerStart=strrpos($mobile,'/* Workers AI fourth card:');
if($workerStart===false)$fail('Missing Workers AI-specific final responsive layout');
$workerRule=substr($mobile,$workerStart);
foreach([
    '.form-status.provider-badge',
    'grid-column:3 / 4!important;',
    'grid-row:1 / 2!important;',
    'width:max-content!important;',
    'inline-size:max-content!important;',
    'min-width:max-content!important;',
    'max-width:none!important;',
    '> div > .workers-ai-observation'
] as $token){
    if(!str_contains($workerRule,$token))$fail('Workers AI desktop/tablet/mobile parity: '.$token);
}
if(!str_contains($workers,'class="workers-ai-observation"') ||
   !str_contains($workers,'class="provider-badge') ||
   str_contains($workers,'class="form-status provider-badge') ||
   substr_count($workers,'Se o Llama 3.2 Vision retornar uma imagem')!==1)
   $fail('Workers AI note/badge markup is inconsistent with other providers');
echo "INTEGRATION_WORKERS_AI_UNIFIED_LAYOUT_TESTS_PASSED\n";

// Distinct integration implementations use either provider-badge OR
// form-status. Both must fit content, remain in the first-row third cell,
// and sit beside the first-row chevron throughout 320-1024px.
$allStatuses=strrpos($mobile,'/* FINAL tablet/mobile invariant for real provider markup:');
if($allStatuses===false)$fail('Tablet status fallback for mixed badge markup missing');
$all=substr($mobile,$allStatuses);
foreach([
  ':is(.provider-badge,.form-status)',
  'grid-column:3 / 4!important;',
  'grid-row:1 / 2!important;',
  'width:max-content!important;',
  'inline-size:max-content!important;',
  'min-width:max-content!important;',
  'max-width:none!important;',
  'flex-grow:0!important;',
  'flex-shrink:0!important;',
  'margin:0!important;',
  '@media (max-width:400px)'
] as $token){
  if(!str_contains($all,$token))$fail('Unstandardized tablet provider status: '.$token);
}
echo "INTEGRATION_TABLET_MIXED_BADGE_VARIANTS_TESTS_PASSED\n";

// The actual bug was caused by the extra form-status class on Workers AI.
// It must share the EXACT HTML/CSS contract of the three other provider
// badges instead of accumulating overrides with different specificity.
$workersMarkup=(string)file_get_contents($root.'/app/workers-ai-card.php');
if(!str_contains($workersMarkup,'<span class="provider-badge <?=$workersAIConfigured?') ||
   str_contains($workersMarkup,'<span class="form-status provider-badge') ||
   !str_contains($workersMarkup,"\$workersAIConfigured?'Configurado':'Não configurado'")){
    $fail('Workers AI is not using the shared provider-badge HTML / credential-derived status');
}
$commonStatusStart=strpos($desktop,'.translation-provider-grid .provider-badge{');
$commonStatusEnd=strpos($desktop,'.translation-provider-grid .provider-badge::before{',$commonStatusStart);
if($commonStatusStart===false || $commonStatusEnd===false || $commonStatusEnd<=$commonStatusStart)
    $fail('Shared provider-badge stylesheet missing');
$shared=substr($desktop,$commonStatusStart,$commonStatusEnd-$commonStatusStart);
foreach(['display:inline-flex!important;','border-radius:999px!important;','white-space:nowrap!important;'] as $token)
    if(!str_contains($shared,$token))$fail('Shared status style changed unexpectedly: '.$token);
if(!str_contains($mobile,'.translation-provider-grid .provider-badge::before{'))
    $fail('Shared mobile status dot style missing');
echo "INTEGRATION_WORKERS_SHARED_BADGE_MARKUP_PARITY_TESTS_PASSED\n";
