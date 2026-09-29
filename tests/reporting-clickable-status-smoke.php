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
$repo=(string)file_get_contents(__DIR__.'/../app/Reporting/Repository.php');
$schema=(string)file_get_contents(__DIR__.'/../app/Reporting/Schema.php');

ok(str_contains($page,'class="reporting-status-badge reporting-status-edit'),'status do historico e clicavel');
ok(str_contains($page,'data-ticket-id="<?=rh($ticket[\'id\'])?>"'),'botao identifica o ticket');
ok(str_contains($page,'name="action" value="history_status_update"'),'editor envia acao manual');
ok(str_contains($page,'id="reporting-status-editor"'),'pagina possui editor de status');
ok(str_contains($page,'A API-Football só voltará a consultar quando você definir o status como Pendente'),'editor informa regra de retorno a fila');
ok(str_contains($page,"'PENDING'=>['Pendente','pending']"),'editor permite reabrir como pendente');
ok(str_contains($page,"'GREEN'=>['Green','green']"),'editor permite green');
ok(str_contains($page,"'RED'=>['Red','red']"),'editor permite red');
ok(str_contains($page,"'VOID'=>['Void','void']"),'editor permite void');
ok(str_contains($page,"'HALF_GREEN'=>['Half Green','half-green']"),'editor permite half green');
ok(str_contains($page,"'HALF_RED'=>['Half Red','half-red']"),'editor permite half red');
ok(str_contains($page,"'REVIEW'=>['Revisão','review']"),'editor permite revisao');
ok(str_contains($page,"target.closest('.reporting-status-edit')"),'clique no selo abre editor');
ok(str_contains($page,'/assets/reporting.css?v=12'),'editor usa CSS atualizado');

ok(str_contains($css,'.reporting-status-edit{'),'status clicavel possui estilo proprio');
ok(str_contains($css,'.reporting-status-editor-options{'),'opcoes do editor seguem layout do projeto');
ok(str_contains($css,'@media(max-width:520px)'),'editor possui tratamento responsivo');

ok(str_contains($repo,'public static function manualSetTicketStatus(int $ticketId, string $status): void'),'repositorio altera status de qualquer ticket');
ok(str_contains($repo,'manual_status_override'),'override manual e persistente');
ok(str_contains($repo,'manual_status_pending'),'pendente devolve ticket para a fila');
ok(str_contains($repo,'Qualquer status manual diferente de PENDING encerra a aposta'),'status manual terminal encerra fila automatica');
ok(str_contains($repo,'next_check_at=NULL'),'status manual terminal cancela nova consulta');
ok(str_contains($repo,'manual_ticket_status'),'status manual terminal registra origem manual');
ok(str_contains($schema,'manual_status_override VARCHAR(32) NULL'),'schema possui override manual');

echo "REPORTING_CLICKABLE_STATUS_SMOKE_PASSED\n";
