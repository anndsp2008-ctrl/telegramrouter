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
$aiLearning=(string)file_get_contents(__DIR__.'/../ai-learning.php');
$themeJs=(string)file_get_contents(__DIR__.'/../assets/brand/theme.js');
$themeCss=(string)file_get_contents(__DIR__.'/../assets/brand/theme.css');
$themeToggle=(string)file_get_contents(__DIR__.'/../app/theme-toggle.php');

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
foreach([
    'index'=>$index,
    'reports'=>$reports,
    'connect'=>$connect,
    'reset'=>$reset,
    'ai-learning'=>$aiLearning,
] as $name=>$page){
    ok(str_contains($page,'/assets/brand/theme.js?v=1'),$name.' carrega inicializacao do tema');
    ok(str_contains($page,'/assets/brand/theme.css?v=9'),$name.' carrega estilo do tema');
}
foreach([
    'index'=>$index,
    'reports'=>$reports,
    'reset'=>$reset,
    'ai-learning'=>$aiLearning,
] as $name=>$page){
    ok(str_contains($page,"require __DIR__.'/app/theme-toggle.php'"),$name.' exibe alternador ao lado do logout');
}
ok(str_contains($themeToggle,'data-theme-toggle'),'componente global possui botao de tema');
ok(str_contains($themeJs,"localStorage.getItem(STORAGE_KEY)"),'tema persiste preferencia no navegador');
ok(str_contains($themeJs,'document.documentElement.dataset.theme'),'tema e aplicado antes da interface');
ok(str_contains($themeJs,"current===LIGHT?DARK:LIGHT"),'botao alterna claro e escuro');
ok(str_contains($themeCss,'html[data-theme="light"]'),'tema claro possui escopo global');
ok(str_contains($themeCss,'.reporting-history-row-dark>td'),'tema claro preserva zebrado dos relatorios');
ok(str_contains($themeCss,'.tmr-theme-toggle+form'),'alternador permanece ao lado do logout no responsivo');

ok(str_contains($index,'class="routing-delete" data-dialog-confirm'),'exclusao de regra exige confirmacao');
ok(!str_contains($reports,"onsubmit=\"return confirm("),'relatorios nao usam confirm nativo');
ok(str_contains($reports,'reporting-review-resolve-form" data-dialog-confirm'),'resultado manual usa confirmacao canonica');
ok(str_contains($connect,'disconnect-form" data-dialog-confirm'),'desconexao usa confirmacao canonica');
ok(!str_contains($reset,"alert('Selecione pelo menos uma categoria"),'reset nao usa alert nativo');
ok(str_contains($reset,'window.tmrDialog.alert'),'reset usa aviso canonico');

echo "PROJECT_DIALOGS_SMOKE_PASSED\n";
