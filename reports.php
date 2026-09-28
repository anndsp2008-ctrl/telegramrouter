<?php declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Auth;
use App\Database;
use App\Repository as RouterRepository;
use App\Reporting\Repository;
use App\Reporting\Schema;

Auth::requireLogin();
Schema::migrate();

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Auth::verifyCsrf($_POST['csrf'] ?? null);
        Repository::saveSettings([
            'enabled' => isset($_POST['enabled']),
            'scope' => (($_POST['scope'] ?? 'all') === 'selected' ? 'selected' : 'all'),
            'check_results' => isset($_POST['check_results']),
            'daily_report' => isset($_POST['daily_report']),
            'report_time' => (string)($_POST['report_time'] ?? '00:00'),
            'timezone' => 'America/Sao_Paulo',
            'report_chat' => (string)($_POST['report_chat'] ?? ''),
        ]);
        Repository::saveRuleScope((array)($_POST['rules'] ?? []));
        $notice = 'Configurações de relatórios atualizadas.';
    } catch (Throwable $e) {
        $error = 'Não foi possível salvar as configurações.';
    }
}

$settings = Repository::settings();
$rules = RouterRepository::allRules();
$selectedRules = Repository::selectedRuleIds();
$tickets = Repository::recentTickets(50);
$stateRows = Database::pdo()->query(
    'SELECT state_key,state_value,updated_at FROM reporting_runtime_state ORDER BY state_key'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$state = [];
foreach ($stateRows as $row) {
    $state[(string)$row['state_key']] = $row;
}

function rh(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rstatus(string $status): string
{
    return match ($status) {
        'GREEN' => '🟢 GREEN',
        'RED' => '🔴 RED',
        'VOID' => '⚪ VOID',
        'HALF_GREEN' => '🟡 HALF GREEN',
        'HALF_RED' => '🟠 HALF RED',
        'REVIEW' => '🔎 REVISÃO',
        default => '⏳ PENDENTE',
    };
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Relatórios · Telegram Router</title>
    <link rel="stylesheet" href="/assets/saas.css">
    <style>
        .report-wrap{max-width:1180px;margin:0 auto;padding:28px}
        .report-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:22px}
        .report-head h1{margin:4px 0 6px}
        .report-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
        .report-card{padding:22px;border-radius:18px;background:var(--card-bg,#fff);border:1px solid var(--border,#dfe5ea)}
        .report-card label{display:block;margin:12px 0}
        .report-card input[type="text"],.report-card input[type="time"]{width:100%;box-sizing:border-box;padding:11px 12px;border-radius:10px;border:1px solid #cfd8df}
        .report-table{width:100%;border-collapse:collapse;font-size:14px}
        .report-table th,.report-table td{text-align:left;padding:11px 9px;border-bottom:1px solid #e7ecef}
        .report-muted{opacity:.72}
        .report-actions{margin-top:18px}
        .report-actions button,.report-back{display:inline-flex;align-items:center;padding:10px 16px;border-radius:10px;text-decoration:none;border:0;cursor:pointer}
        .report-actions button{background:#183f43;color:#fff}
        .report-back{background:#eef3f4;color:#183f43}
        .report-flash{padding:12px 14px;border-radius:10px;margin-bottom:16px}
        .report-flash.ok{background:#e9fff1}
        .report-flash.error{background:#fff0f1}
        @media(max-width:820px){.report-grid{grid-template-columns:1fr}.report-wrap{padding:18px}.report-head{flex-direction:column}}
    </style>
</head>
<body>
<main class="report-wrap">
    <div class="report-head">
        <div>
            <div class="report-muted">RELATÓRIOS</div>
            <h1>Resultados automáticos</h1>
            <p class="report-muted">Um único módulo para captura, liquidação e relatório diário.</p>
        </div>
        <a class="report-back" href="/">← Painel</a>
    </div>

    <?php if ($notice !== null): ?><div class="report-flash ok"><?=rh($notice)?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="report-flash error"><?=rh($error)?></div><?php endif; ?>

    <div class="report-grid">
        <section class="report-card">
            <h2>Configuração</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>">
                <label><input type="checkbox" name="enabled" <?=!empty($settings['enabled'])?'checked':''?>> Ativar sistema de relatórios</label>
                <fieldset style="border:0;padding:0;margin:16px 0">
                    <legend style="font-weight:600;margin-bottom:8px">Escopo do acompanhamento</legend>
                    <label><input type="radio" name="scope" value="all" <?=($settings['scope']??'all')==='all'?'checked':''?>> Todos os canais/regras</label>
                    <label><input type="radio" name="scope" value="selected" <?=($settings['scope']??'all')==='selected'?'checked':''?>> Somente canais/regras selecionados</label>
                </fieldset>
                <div style="max-height:280px;overflow:auto;border:1px solid #dfe5ea;border-radius:12px;padding:10px 12px;margin-bottom:14px">
                    <?php foreach ($rules as $rule): ?>
                        <label style="display:flex;gap:9px;align-items:flex-start">
                            <input type="checkbox" name="rules[]" value="<?=rh($rule['id'])?>" <?=in_array((int)$rule['id'],$selectedRules,true)?'checked':''?>>
                            <span><strong><?=rh($rule['source_chat'])?></strong> → <?=rh($rule['destination_chat'])?><?php if(trim((string)$rule['trigger_text'])!==''): ?><br><small class="report-muted">Gatilho: <?=rh($rule['trigger_text'])?></small><?php endif; ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if ($rules === []): ?><div class="report-muted">Nenhuma regra de roteamento cadastrada.</div><?php endif; ?>
                </div>
                <label><input type="checkbox" name="check_results" <?=!empty($settings['check_results'])?'checked':''?>> Verificar resultados automaticamente</label>
                <label><input type="checkbox" name="daily_report" <?=!empty($settings['daily_report'])?'checked':''?>> Gerar relatório diário</label>
                <label>Horário do relatório
                    <input type="time" name="report_time" value="<?=rh(substr((string)$settings['report_time'],0,5))?>">
                </label>
                <label>Canal específico do relatório
                    <input type="text" name="report_chat" value="<?=rh($settings['report_chat'])?>" placeholder="Vazio = mesmo canal de destino das apostas">
                </label>
                <p class="report-muted">Fuso fixo: America/Sao_Paulo. Se o canal ficar vazio, cada destino recebe somente o relatório das próprias apostas.</p>
                <div class="report-actions"><button type="submit">Salvar</button></div>
            </form>
        </section>

        <section class="report-card">
            <h2>Estado do worker</h2>
            <table class="report-table">
                <tbody>
                <?php foreach (['scheduler_status','scheduler_heartbeat_at','worker_status','worker_last_run_at','daily_report_last_check_at'] as $key): ?>
                    <tr>
                        <th><?=rh($key)?></th>
                        <td><?=rh($state[$key]['state_value'] ?? 'Sem registro')?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <section class="report-card" style="margin-top:18px">
        <h2>Últimas apostas acompanhadas</h2>
        <div style="overflow:auto">
            <table class="report-table">
                <thead>
                <tr><th>ID</th><th>Destino</th><th>Tipo</th><th>Odd</th><th>Status</th><th>Resultado</th><th>Registrada</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tickets as $ticket): ?>
                    <tr>
                        <td><?=rh($ticket['id'])?></td>
                        <td><?=rh($ticket['destination_chat'])?></td>
                        <td><?=rh($ticket['bet_kind'])?></td>
                        <td><?=rh($ticket['total_odds'] ?? '—')?></td>
                        <td><?=rh(rstatus((string)$ticket['status']))?></td>
                        <td><?=rh(number_format((float)$ticket['profit_units'],2,',','.'))?> un.</td>
                        <td><?=rh($ticket['placed_at'])?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($tickets === []): ?>
                    <tr><td colspan="7" class="report-muted">Nenhuma aposta acompanhada ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
