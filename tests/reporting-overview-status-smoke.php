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

ok(str_contains($css,'.reporting-page .overview-status-meta{'),'status do modulo tem regra especifica');
ok(str_contains($css,'grid-auto-rows:max-content'),'linhas do status usam altura natural');
ok(str_contains($css,'row-gap:5px'),'desktop separa linhas do status');
ok(str_contains($css,'position:static!important'),'remove posicionamento que poderia sobrepor texto');
ok(str_contains($css,'@media(max-width:820px)'),'correcao cobre tablet e mobile');
ok(str_contains($css,'grid-column:1/-1!important'),'meta ocupa linha propria no responsivo');
ok(str_contains($css,'border-top:1px solid #ffffff0d'),'rodape do status recebe separacao visual');
ok(str_contains($css,'row-gap:7px!important'),'mobile separa worker, valor e escopo');
ok(str_contains($css,'text-align:left!important'),'mobile alinha metadados sem colisao');
ok(str_contains($page,'/assets/reporting.css?v=7'),'pagina invalida cache do CSS corrigido');

echo "REPORTING_OVERVIEW_STATUS_SMOKE_PASSED\n";
