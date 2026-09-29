<?php declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\TelegramRouter;

function ok(bool $condition,string $label): void {
    if(!$condition){
        fwrite(STDERR,"FAIL {$label}\n");
        exit(1);
    }
    echo "OK {$label}\n";
}

$trigger=new ReflectionMethod(TelegramRouter::class,'triggerMatches');
$trigger->setAccessible(true);
$exclude=new ReflectionMethod(TelegramRouter::class,'exclusionMatch');
$exclude->setAccessible(true);

ok($trigger->invoke(null,'PRONÓSTICO DO DIA','pronóstico')===true,'gatilho continua case-insensitive');
ok($trigger->invoke(null,'PRONÓSTICO VIP DE HOJE','PRONÓSTICO + HOJE')===true,'gatilho positivo preserva AND com +');

$rules="CASHOUT\nRESULTADO FINAL\nAPOSTA + FINALIZADA";
ok($exclude->invoke(null,'PRONÓSTICO - cashout disponível',$rules)==='CASHOUT','uma linha de exclusao bloqueia por OR');
ok($exclude->invoke(null,'PRONÓSTICO - aposta já FINALIZADA',$rules)==='APOSTA + FINALIZADA','linha de exclusao preserva AND com +');
ok($exclude->invoke(null,'PRONÓSTICO - aposta aberta',$rules)===null,'mensagem sem exclusao continua elegivel');
ok($exclude->invoke(null,'QUALQUER TEXTO','')===null,'exclusao vazia nao bloqueia');

$router=file_get_contents(__DIR__.'/../app/TelegramRouter.php');
$repository=file_get_contents(__DIR__.'/../app/Repository.php');
$database=file_get_contents(__DIR__.'/../app/Database.php');
$schema=file_get_contents(__DIR__.'/../database/schema.sql');
$panel=file_get_contents(__DIR__.'/../index.php');

ok(is_string($router) && strpos($router,'exclusionMatch($text') < strpos($router,'$this->process($message,$source,$rule)'), 'exclusao ocorre antes de qualquer processamento');
ok(is_string($router) && str_contains($router,'Nenhum encaminhamento, tradução ou IA foi executado.'),'historico registra bloqueio sem IA');
ok(is_string($repository) && str_contains($repository,'exclude_text'),'repositorio persiste exclusao');
ok(is_string($database) && str_contains($database,'ADD COLUMN exclude_text TEXT NULL AFTER trigger_text'),'migracao adiciona exclusao');
ok(is_string($schema) && str_contains($schema,'exclude_text TEXT NULL'),'schema de instalacao inclui exclusao');
ok(is_string($panel) && str_contains($panel,'name="exclude_text"'),'painel expoe campo de exclusao');
ok(is_string($panel) && str_contains($panel,'APOSTA + FINALIZADA'),'painel documenta AND na exclusao');

echo "ROUTING_EXCLUSION_SMOKE_PASSED\n";
