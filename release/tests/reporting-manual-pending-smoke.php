<?php declare(strict_types=1);

function ok(bool $condition,string $label): void {
    if(!$condition){
        fwrite(STDERR,"FAIL {$label}\n");
        exit(1);
    }
    echo "OK {$label}\n";
}

$page=(string)file_get_contents(__DIR__.'/../reports.php');
$css=(string)file_get_contents(__DIR__.'/../assets/reporting.css');
$worker=(string)file_get_contents(__DIR__.'/../scripts/report-worker.php');
$repo=(string)file_get_contents(__DIR__.'/../app/Reporting/Repository.php');

ok(str_contains($page,'name="action" value="force_pending_check"'),'historico possui acao manual');
ok(str_contains($page,'class="reporting-pending-count"'),'historico exibe selo de pendentes');
ok(str_contains($page,'class="reporting-force-pending-btn"'),'historico possui botao ao lado do selo');
ok(str_contains($page,'data-dialog-title="Reverificar todas as pendentes?"'),'acao manual possui confirmacao padronizada');
ok(str_contains($page,'/assets/reporting.css?v=10'),'pagina invalida cache do novo estilo');

ok(str_contains($css,'.reporting-history-actions{'),'acoes do historico possuem layout proprio');
ok(str_contains($css,'.reporting-force-pending-btn{'),'botao manual segue estilo do projeto');
ok(str_contains($css,'@media(max-width:480px)'),'botao manual possui tratamento responsivo');

ok(str_contains($repo,'public static function requestManualPendingCheck(): int'),'repositorio enfileira verificacao manual');
ok(str_contains($repo,'public static function consumeManualPendingCheckRequest(): bool'),'repositorio expõe consumo da solicitacao');
ok(str_contains($worker,'Repository::consumeManualPendingCheckRequest()'),'worker observa solicitacao manual');
ok(str_contains($worker,'|| $manualPendingCheck'),'solicitacao manual ignora espera normal de cinco minutos');

echo "REPORTING_MANUAL_PENDING_SMOKE_PASSED\n";
