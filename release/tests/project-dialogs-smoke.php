<?php declare(strict_types=1);

function ok(bool $condition,string $label): void {
    if(!$condition){
        fwrite(STDERR,"FAIL {$label}\n");
        exit(1);
    }
    echo "OK {$label}\n";
}

$dialogs=(string)file_get_contents(__DIR__.'/../assets/dialogs.js');
$css=(string)file_get_contents(__DIR__.'/../assets/dialogs.css');
$index=(string)file_get_contents(__DIR__.'/../index.php');
$reports=(string)file_get_contents(__DIR__.'/../reports.php');
$connect=(string)file_get_contents(__DIR__.'/../connect.php');
$reset=(string)file_get_contents(__DIR__.'/../reset.php');

ok(str_contains($dialogs,'window.tmrDialog = { show, confirm, alert'),'API global de dialogos disponivel');
ok(str_contains($dialogs,"form.classList.contains('routing-delete')"),'exclusao de regra usa dialogo');
ok(str_contains($dialogs,"form.classList.contains('reporting-review-resolve-form')"),'resultado manual usa dialogo');
ok(str_contains($dialogs,"form.classList.contains('disconnect-form')"),'desconexao Telegram usa dialogo');
ok(str_contains($dialogs,"document.addEventListener('submit', async event"),'confirmacao intercepta formularios');
ok(str_contains($dialogs,"event.stopImmediatePropagation()"),'confirmacao ocorre antes de submit assincrono');
ok(str_contains($dialogs,"form.dataset.dialogApproved = '1'"),'submit aprovado nao entra em loop');
ok(str_contains($dialogs,"event.key === 'Escape'"),'dialogo suporta tecla Escape');
ok(str_contains($dialogs,"event.key !== 'Tab'"),'dialogo controla foco por teclado');

ok(str_contains($css,'.tmr-dialog-overlay'),'overlay canonico estilizado');
ok(str_contains($css,'.tmr-dialog[data-tone="danger"]'),'variante destrutiva estilizada');
ok(str_contains($css,'@media(max-width:640px)'),'dialogo responsivo');
ok(str_contains($css,'prefers-reduced-motion'),'dialogo respeita reducao de movimento');

foreach([
    'index'=>$index,
    'reports'=>$reports,
    'connect'=>$connect,
    'reset'=>$reset,
] as $name=>$page){
    ok(str_contains($page,'/assets/dialogs.css?v=3'),$name.' carrega estilo canonico');
    ok(str_contains($page,'/assets/dialogs.js?v=3'),$name.' carrega comportamento canonico');
}

ok(str_contains($index,'class="routing-delete" data-dialog-confirm'),'exclusao de regra exige confirmacao');
ok(!str_contains($reports,"onsubmit=\"return confirm("),'relatorios nao usam confirm nativo');
ok(str_contains($reports,'reporting-review-resolve-form" data-dialog-confirm'),'resultado manual usa confirmacao canonica');
ok(str_contains($connect,'disconnect-form" data-dialog-confirm'),'desconexao usa confirmacao canonica');
ok(!str_contains($reset,"alert('Selecione pelo menos uma categoria"),'reset nao usa alert nativo');
ok(str_contains($reset,'window.tmrDialog.alert'),'reset usa aviso canonico');

echo "PROJECT_DIALOGS_SMOKE_PASSED\n";
