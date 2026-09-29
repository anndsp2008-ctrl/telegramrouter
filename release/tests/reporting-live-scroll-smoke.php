<?php declare(strict_types=1);

function ok(bool $condition,string $label): void {
    if(!$condition){
        fwrite(STDERR,"FAIL {$label}\n");
        exit(1);
    }
    echo "OK {$label}\n";
}

$live=(string)file_get_contents(__DIR__.'/../assets/live.js');
$reports=(string)file_get_contents(__DIR__.'/../reports.php');

ok(str_contains($live,"isReportingPage() ? 15000 : 1500"),'relatorios usam intervalo de 15 segundos');
ok(str_contains($live,'reportingInteractionGraceMs = 7000'),'interacao pausa refresh por janela de seguranca');
ok(str_contains($live,"#reporting-history .saas-table-wrap"),'historico responsivo e monitorado');
ok(str_contains($live,'historyLeft: wrap instanceof HTMLElement ? wrap.scrollLeft : 0'),'scroll horizontal e preservado');
ok(str_contains($live,'wrap.scrollLeft = state.historyLeft'),'scroll horizontal e restaurado');
ok(str_contains($live,'.reporting-history-toggle[aria-expanded="true"]'),'accordion aberto e capturado');
ok(str_contains($live,"toggle.setAttribute('aria-expanded', 'true')"),'accordion aberto e restaurado');
ok(str_contains($live,"if (!shouldPause()) replaceMain(html);"),'resposta nao redesenha tabela se interacao iniciou durante fetch');
ok(str_contains($live,"['pointerdown', 'pointermove', 'touchstart', 'touchmove', 'wheel']"),'toque arrasto e roda pausam atualizacao');
ok(str_contains($live,"document.body.classList.contains('tmr-dialog-lock')"),'live refresh pausa enquanto dialogo esta aberto');
ok(str_contains($reports,'/assets/live.js?v=3'),'relatorios invalidam cache do live js v3');

echo "REPORTING_LIVE_SCROLL_SMOKE_PASSED\n";
