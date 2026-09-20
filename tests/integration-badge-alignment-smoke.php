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
    ['stylesheet cache v10', $installer, '/assets/brand/integrations-v10.css?v=9'],
    ['responsive cache v10', $installer, '/assets/brand/mobile-visual-audit.css?v=10']
];
foreach($required as [$label,$source,$token]){
    if(!str_contains($source,$token))$fail('Integration badge alignment regression: '.$label);
}
if(str_contains($desktop,'.translation-provider-grid .provider-badge{'."\n".'    display:none!important;'))
    $fail('Provider badge is hidden at a mobile breakpoint');
$end=strrpos($mobile,'/* Integration status alignment invariant:');
if($end===false)$fail('Missing final cross-breakpoint alignment rule');
$invariant=substr($mobile,$end);
foreach(['@media (max-width:1024px)','@media (max-width:640px)',
          '> .provider-badge','margin-left:auto!important;',
          'margin-right:0!important;','justify-self:end!important;',
          'grid-column:2 / 4!important;','grid-row:2!important;'] as $requiredToken){
    if(!str_contains($invariant,$requiredToken))
        $fail('Cross-breakpoint right-side invariant missing: '.$requiredToken);
}
if(str_contains($invariant,'justify-self:start!important;')||
   str_contains($invariant,'grid-column:1 / -1!important;'))
    $fail('Late mobile rule can move the badge back to the left');
echo "INTEGRATION_BADGE_RIGHT_ALL_VIEWPORTS_TESTS_PASSED\n";
