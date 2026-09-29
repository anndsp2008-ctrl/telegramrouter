<?php declare(strict_types=1);

require __DIR__.'/../config/config.php';

$host=(string)(getenv('DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('DB_PORT')?:'3306');
$name=(string)(getenv('DB_NAME')?:'telegramrouter_test');
$user=(string)(getenv('DB_USER')?:'root');
$pass=(string)(getenv('DB_PASS')?:'root');

$raw=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);

$raw->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
    'reporting_outbox','reporting_daily_reports','reporting_legs','reporting_tickets',
    'reporting_rule_scope','reporting_settings','reporting_runtime_state',
    'result_tracking_rules','result_tracking_daily_reports','result_tracking_bets','result_tracking_settings',
    'router_rules'
] as $table){
    $raw->exec('DROP TABLE IF EXISTS '.$table);
}
$raw->exec('SET FOREIGN_KEY_CHECKS=1');

$raw->exec("
    CREATE TABLE router_rules(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_chat VARCHAR(255) NOT NULL,
        destination_chat VARCHAR(255) NOT NULL,
        trigger_text VARCHAR(255) NOT NULL DEFAULT '',
        remove_emojis TINYINT(1) NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$raw->exec("CREATE TABLE result_tracking_settings(id TINYINT PRIMARY KEY)");
$raw->exec("CREATE TABLE result_tracking_bets(id BIGINT PRIMARY KEY)");
$raw->exec("CREATE TABLE result_tracking_daily_reports(report_date DATE PRIMARY KEY)");
$raw->exec("CREATE TABLE result_tracking_rules(rule_id BIGINT PRIMARY KEY)");

require __DIR__.'/../app/Database.php';
require __DIR__.'/../vendor/autoload.php';

use App\Database;
use App\Reporting\DailyReportService;
use App\Reporting\Repository;
use App\Reporting\Schema;
use App\Reporting\SettlementEngine;
use App\Reporting\TicketNormalizer;

function ok(bool $condition,string $label): void {
    if(!$condition){
        fwrite(STDERR,"FAIL {$label}\n");
        exit(1);
    }
    echo "OK {$label}\n";
}

Schema::migrate();
$pdo=Database::pdo();

$excludeColumn=$pdo->query("SHOW COLUMNS FROM router_rules LIKE 'exclude_text'")->fetch(PDO::FETCH_ASSOC);
ok(is_array($excludeColumn),'migracao cria coluna de exclusao nas regras');

foreach(['result_tracking_settings','result_tracking_bets','result_tracking_daily_reports','result_tracking_rules'] as $legacy){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $q->execute([$legacy]);
    ok((int)$q->fetchColumn()===0,'legacy removido '.$legacy);
}

$settings=Repository::settings();
ok((int)$settings['enabled']===0,'modulo inicia desativado');
ok(($settings['scope']??'')==='all','escopo padrao todos');
ok((int)$settings['check_results']===1,'verificacao preselecionada');
ok((int)$settings['daily_report']===1,'relatorio diario preselecionado');

$pdo->exec("INSERT INTO router_rules(id,source_chat,destination_chat,trigger_text,enabled) VALUES
    (11,'-100sourceA','-100destA','',1),
    (12,'-100sourceB','-100destB','',1)");

Repository::saveSettings([
    'enabled'=>1,
    'scope'=>'selected',
    'check_results'=>1,
    'daily_report'=>1,
    'report_time'=>'00:00',
    'timezone'=>'America/Sao_Paulo',
    'report_chat'=>'-100reports',
]);
Repository::saveRuleScope([11]);

ok(Repository::ruleEnabled(11),'regra selecionada habilitada');
ok(!Repository::ruleEnabled(12),'regra nao selecionada ignorada');

$model=[
    'kind'=>'simple',
    'bookmaker'=>'Teste',
    'odd'=>'1.60',
    'legs'=>[[
        'sport'=>'Futebol',
        'match'=>'Manchester City x Arsenal',
        'league'=>'Premier League',
        'date'=>(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y'),
        'market'=>'Total de escanteios',
        'selection'=>'Mais de 8,5 escanteios',
        'odd'=>'1.60',
    ]],
];

$ticket=TicketNormalizer::fromModel($model);
ok(is_array($ticket),'ticket normalizado');
$id=Repository::captureTicket($ticket,[
    'rule_id'=>11,
    'source_chat'=>'-100sourceA',
    'destination_chat'=>'-100destA',
    'message_id'=>9001,
]);
ok(is_int($id)&&$id>0,'ticket capturado');

Repository::captureTicket($ticket,[
    'rule_id'=>11,
    'source_chat'=>'-100sourceA',
    'destination_chat'=>'-100destA',
    'message_id'=>9001,
]);
ok((int)$pdo->query('SELECT COUNT(*) FROM reporting_tickets')->fetchColumn()===1,'ticket deduplicado');
ok((int)$pdo->query('SELECT COUNT(*) FROM reporting_legs')->fetchColumn()===1,'leg deduplicada');

$recentTickets=Repository::recentTickets(50);
ok(count($recentTickets)===1,'historico retorna ticket capturado');
ok(count((array)($recentTickets[0]['legs']??[]))===1,'historico retorna pernas do ticket');
ok(($recentTickets[0]['legs'][0]['match_name']??'')==='Manchester City vs Arsenal','historico inclui jogo padronizado com vs');
ok(($recentTickets[0]['legs'][0]['market_text']??'')==='Total de escanteios','historico inclui mercado');
ok(($recentTickets[0]['legs'][0]['selection_text']??'')==='Mais de 8,5 escanteios','historico inclui selecao');

$pdo->exec(
    'UPDATE reporting_legs
     SET next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 HOUR),
         lookup_attempts=1,
         lookup_last_reason="not_found"
     WHERE id='.(int)$id
);
$requeued=Repository::requeuePendingUnmatchedForRetryPolicyOnce();
ok($requeued===1,'reagenda pendencia sem fixture imediatamente');
$requeuedAgain=Repository::requeuePendingUnmatchedForRetryPolicyOnce();
ok($requeuedAgain===0,'reagendamento de politica executa uma unica vez');
$retryRow=$pdo->query('SELECT lookup_attempts,lookup_last_reason,next_check_at<=UTC_TIMESTAMP() AS due_now FROM reporting_legs LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok((int)($retryRow['lookup_attempts']??-1)===0,'reagendamento zera tentativas antigas');
ok(($retryRow['lookup_last_reason']??'')==='retry_policy_requeue','reagendamento registra motivo');
ok((int)($retryRow['due_now']??0)===1,'reagendamento deixa consulta vencida agora');

$attempts=Repository::recordLookupFailure((int)$id,'not_found');
ok($attempts===1,'falha de lookup contabilizada');
$retryAt=(string)$pdo->query('SELECT next_check_at FROM reporting_legs LIMIT 1')->fetchColumn();
$retryUtc=new DateTimeImmutable($retryAt,new DateTimeZone('UTC'));
$retryLocal=$retryUtc->setTimezone(new DateTimeZone('America/Sao_Paulo'));
$retrySeconds=$retryUtc->getTimestamp()-time();
ok($retryLocal->format('H:i')==='00:15','retry economico fica no sweep de 00:15');
ok($retrySeconds>0 && $retrySeconds<=86460,'retry economico ocorre apenas na proxima janela noturna');

$pdo->exec(
    'UPDATE reporting_legs
     SET fixture_id=12345,
         next_check_at=UTC_TIMESTAMP(),
         lookup_attempts=0,
         lookup_last_reason=NULL
     WHERE id='.(int)$id
);
ok(Repository::fixtureHasPendingLegs(12345)===true,'fixture pendente permanece elegivel para consulta');
ok(in_array(12345,Repository::dueFixtureIds(),true),'fixture pendente entra na fila antes da liquidacao');

$leg=$pdo->query('SELECT * FROM reporting_legs LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$result=SettlementEngine::settle($leg,[
    'home_team'=>'Manchester City',
    'away_team'=>'Arsenal',
    'corners_home'=>6,
    'corners_away'=>4,
]);
ok(($result['status']??'')===SettlementEngine::GREEN,'settlement green');
Repository::settleLeg((int)$leg['id'],$result,['test'=>true]);
ok(Repository::fixtureHasPendingLegs((int)($leg['fixture_id']??0))===false,'fixture liquidado nao permanece pendente');
ok(Repository::dueFixtureIds()===[],'fixture liquidado sai definitivamente da fila de consulta');

$stored=$pdo->query('SELECT status,profit_units FROM reporting_tickets LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok(($stored['status']??'')==='GREEN','ticket consolidado green');
ok(abs((float)$stored['profit_units']-6.0)<0.0001,'lucro simples correto');

$date=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
$stats=Repository::reportStats($date,'America/Sao_Paulo',null);
ok((int)($stats['total']??0)===1,'relatorio contabiliza ticket');
ok((int)($stats['greens']??0)===1,'relatorio contabiliza green');
ok(abs((float)($stats['profit']??0)-6.0)<0.0001,'relatorio contabiliza lucro');

$payload=DailyReportService::format($date,$stats);
ok(str_contains($payload,'Greens: 1'),'payload contem greens');
ok(str_contains($payload,'+6,00 unidades'),'payload contem lucro');

ok(Repository::enqueueDailyReport($date,'-100reports',$payload),'outbox criada');
ok(!Repository::enqueueDailyReport($date,'-100reports',$payload),'outbox deduplicada');
ok((int)$pdo->query('SELECT COUNT(*) FROM reporting_outbox')->fetchColumn()===1,'uma unica mensagem na outbox');

$outbox=Repository::claimOutbox();
ok(is_array($outbox),'outbox reivindicada');
Repository::markOutboxSent((int)$outbox['id'],12345);

$status=$pdo->query('SELECT status FROM reporting_daily_reports LIMIT 1')->fetchColumn();
ok($status==='SENT','relatorio marcado enviado');
ok((int)$pdo->query('SELECT COUNT(*) FROM reporting_outbox WHERE status="SENT"')->fetchColumn()===1,'outbox marcada enviada');

echo "REPORTING_DATABASE_SMOKE_PASSED\n";
