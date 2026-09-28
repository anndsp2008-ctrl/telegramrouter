<?php declare(strict_types=1);

$reports=(string)file_get_contents(__DIR__.'/../reports.php');
$css=(string)file_get_contents(__DIR__.'/../assets/reporting.css');

$checks=[
    'accordion toggle class'=>str_contains($reports,'reporting-history-toggle'),
    'accordion target binding'=>str_contains($reports,'data-history-target'),
    'accordion accessibility'=>str_contains($reports,'aria-expanded="false"'),
    'accordion detail row'=>str_contains($reports,'reporting-history-accordion-row'),
    'accordion leg cards'=>str_contains($reports,'reporting-history-accordion-leg'),
    'double/multiple gating'=>str_contains($reports,"in_array(\$betKind,['double','multiple'],true)"),
    'toggle javascript'=>str_contains($reports,"closest('.reporting-history-toggle')"),
    'hidden detail row'=>str_contains($reports,'hidden>'),
    'accordion visual style'=>str_contains($css,'.reporting-history-accordion{'),
    'expanded arrow style'=>str_contains($css,'.reporting-history-toggle[aria-expanded="true"] svg'),
];

foreach($checks as $label=>$ok){
    if(!$ok){
        fwrite(STDERR,"FAIL {$label}\n");
        exit(1);
    }
    echo "OK {$label}\n";
}

echo "REPORTING_HISTORY_ACCORDION_SMOKE_PASSED\n";
