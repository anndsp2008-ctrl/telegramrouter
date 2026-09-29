<?php declare(strict_types=1);

function ok(bool $condition,string $label): void {
    if(!$condition){
        fwrite(STDERR,"FAIL {$label}\n");
        exit(1);
    }
    echo "OK {$label}\n";
}

$css=(string)file_get_contents(__DIR__.'/../assets/reporting.css');
$page=(string)file_get_contents(__DIR__.'/../reports.php');

ok(str_contains($page,'class="overview-status reporting-overview-status'),'card usa componente isolado');
ok(str_contains($page,'class="reporting-overview-meta"'),'metadados nao usam mais overview-status-meta global');
ok(!str_contains($page,'<div class="overview-status-meta">'),'classe global conflitante foi removida do relatorio');
ok(str_contains($page,'reporting-overview-meta-item'),'worker e escopo possuem blocos independentes');
ok(str_contains($page,'Escopo monitorado'),'escopo possui rotulo proprio');
ok(str_contains($css,'.reporting-page .reporting-overview-status{'),'layout dedicado esta presente');
ok(str_contains($css,'grid-template-columns:40px minmax(0,1fr) minmax(190px,auto)!important'),'desktop usa colunas independentes');
ok(str_contains($css,'.reporting-page .reporting-overview-meta-item>span,'),'filhos recebem reset de layout');
ok(str_contains($css,'height:auto!important'),'texto nao fica preso em altura fixa');
ok(str_contains($css,'position:static!important'),'posicionamento residual e neutralizado');
ok(str_contains($css,'row-gap:12px!important'),'worker e escopo possuem separacao vertical');
ok(str_contains($css,'@media(max-width:820px)'),'tablet e mobile possuem layout proprio');
ok(str_contains($css,'grid-row:2!important'),'metadados ocupam linha exclusiva no responsivo');
ok(str_contains($css,'border-top:1px solid #ffffff0d'),'bloco inferior possui separacao visual');
ok(str_contains($page,'/assets/reporting.css?v=9'),'pagina invalida cache do CSS v9');

echo "REPORTING_OVERVIEW_STATUS_SMOKE_PASSED\n";
