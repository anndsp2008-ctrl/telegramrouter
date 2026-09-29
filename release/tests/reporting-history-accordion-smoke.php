<?php declare(strict_types=1);

$reports=(string)file_get_contents(__DIR__.'/../reports.php');
$css=(string)file_get_contents(__DIR__.'/../assets/reporting.css');

$checks=[
    'accordion toggle class'=>str_contains($reports,'reporting-history-toggle'),
    'accordion target binding'=>str_contains($reports,'data-history-target'),
    'accordion accessibility'=>str_contains($reports,'aria-expanded="false"'),
    'accordion detail row'=>str_contains($reports,'reporting-history-accordion-row'),
    'accordion leg cards'=>str_contains($reports,'reporting-history-accordion-leg'),
    'double/triple/multiple gating'=>str_contains($reports,"in_array(\$betKind,['double','triple','multiple'],true)"),
    'toggle javascript'=>str_contains($reports,"closest('.reporting-history-toggle')"),
    'hidden detail row'=>str_contains($reports,'hidden>'),
    'accordion visual style'=>str_contains($css,'.reporting-history-accordion{'),
    'expanded arrow style'=>str_contains($css,'.reporting-history-toggle[aria-expanded="true"] svg'),
    'compact table width'=>str_contains($css,'min-width:900px'),
    'fixed compact columns'=>str_contains($css,'table-layout:fixed'),
    'compact horizontal padding'=>str_contains($css,'padding-left:6px;padding-right:6px'),
    'history channels column'=>str_contains($reports,'<th>Canais</th>'),
    'history source channel'=>str_contains($reports,"\$ticket['source_chat']"),
    'history destination channel'=>str_contains($reports,"\$ticket['destination_chat']"),
    'stacked route markup'=>str_contains($reports,'reporting-history-route-stack'),
    'source above destination'=>strpos($reports,'reporting-history-route-item is-source') < strpos($reports,'reporting-history-route-item is-destination'),
    'stacked route style'=>str_contains($css,'.reporting-history-route-stack{'),
    'history indexed rows'=>str_contains($reports,'foreach ($tickets as $historyIndex=>$ticket)'),
    'history alternating tone'=>str_contains($reports,"reporting-history-row-light':'reporting-history-row-dark"),
    'history zebra light style'=>str_contains($css,'.reporting-table tbody>.reporting-history-row-light>td{'),
    'history zebra dark style'=>str_contains($css,'.reporting-table tbody>.reporting-history-row-dark>td{'),
    'accordion keeps row tone'=>str_contains($reports,'reporting-history-accordion-row <?=$rowTone?>'),
];

foreach($checks as $label=>$ok){
    if(!$ok){
        fwrite(STDERR,"FAIL {$label}\n");
        exit(1);
    }
    echo "OK {$label}\n";
}

echo "REPORTING_HISTORY_ACCORDION_SMOKE_PASSED\n";
