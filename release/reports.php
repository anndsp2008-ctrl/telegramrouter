<?php declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Auth;
use App\Database;
use App\MatchNameFormatter;
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
        if (($_POST['action'] ?? '') === 'logout') {
            Auth::logout();
            header('Location:/login.php');
            exit;
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'force_pending_check') {
            $currentSettings = Repository::settings();
            if (empty($currentSettings['enabled']) || empty($currentSettings['check_results'])) {
                $error = 'Ative o módulo e a verificação automática antes de reprocessar as pendentes.';
            } else {
                $queued = Repository::requestManualPendingCheck();
                $notice = $queued > 0
                    ? $queued . ' aposta' . ($queued === 1 ? '' : 's') . ' pendente' . ($queued === 1 ? '' : 's') . ' enviada' . ($queued === 1 ? '' : 's') . ' para verificação.'
                    : 'Não há apostas pendentes para verificar.';
            }
        } elseif ($action === 'history_status_update') {
            Repository::manualSetTicketStatus(
                (int)($_POST['ticket_id'] ?? 0),
                strtoupper(trim((string)($_POST['manual_status'] ?? '')))
            );
            $notice = 'Status da aposta atualizado manualmente.';
        } elseif ($action === 'review_reprocess') {
            Repository::reprocessReviewLeg((int)($_POST['leg_id'] ?? 0));
            $notice = 'Aposta enviada para reprocessamento.';
        } elseif ($action === 'review_resolve') {
            Repository::manualResolveLeg(
                (int)($_POST['leg_id'] ?? 0),
                strtoupper(trim((string)($_POST['manual_status'] ?? '')))
            );
            $notice = 'Resultado manual aplicado.';
        } else {
            $scope = (($_POST['scope'] ?? 'all') === 'selected' ? 'selected' : 'all');
        Repository::saveSettings([
            'enabled' => isset($_POST['enabled']),
            'scope' => $scope,
            'check_results' => isset($_POST['check_results']),
            'daily_report' => isset($_POST['daily_report']),
            'report_time' => (string)($_POST['report_time'] ?? '00:00'),
            'timezone' => 'America/Sao_Paulo',
            'report_chat' => (string)($_POST['report_chat'] ?? ''),
        ]);
        $scopeRuleIds = $scope === 'all'
            ? array_map(static fn(array $rule): int => (int)$rule['id'], RouterRepository::allRules())
            : array_map('intval', (array)($_POST['rules'] ?? []));
        Repository::saveRuleScope($scopeRuleIds);
        $notice = 'Configurações de relatórios atualizadas.';
        }
    } catch (Throwable $e) {
        $error = 'Não foi possível salvar as configurações.';
    }
}

$settings = Repository::settings();
$rules = RouterRepository::allRules();
$selectedRules = Repository::selectedRuleIds();
$tickets = Repository::recentTickets(50);
$reviewLegs = Repository::reviewLegs(50);
$stateRows = Database::pdo()->query(
    'SELECT state_key,state_value,updated_at FROM reporting_runtime_state ORDER BY state_key'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$state = [];
foreach ($stateRows as $row) {
    $state[(string)$row['state_key']] = $row;
}

$overview = Database::pdo()->query(
    'SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status="GREEN"),0) AS greens,
        COALESCE(SUM(status="RED"),0) AS reds,
        COALESCE(SUM(status="VOID"),0) AS voids,
        COALESCE(SUM(status="HALF_GREEN"),0) AS half_greens,
        COALESCE(SUM(status="HALF_RED"),0) AS half_reds,
        COALESCE(SUM(status="PENDING"),0) AS pending,
        COALESCE(SUM(status="REVIEW"),0) AS review_count,
        COALESCE(SUM(profit_units),0) AS profit
     FROM reporting_tickets'
)->fetch(PDO::FETCH_ASSOC) ?: [];

$credentials = RouterRepository::credentials();
$connectedPhone = (string)($credentials['telegram_phone'] ?? '');
if ($connectedPhone !== '' && $connectedPhone[0] !== '+') {
    $connectedPhone = '+' . $connectedPhone;
}

$hour = (int)(new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('G');
$greeting = ($hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite')) . ', Anderson';
$workerStatus = strtolower((string)($state['worker_status']['state_value'] ?? 'sem registro'));
$schedulerStatus = strtolower((string)($state['scheduler_status']['state_value'] ?? 'sem registro'));
$systemEnabled = !empty($settings['enabled']);
$scopeSelected = (($settings['scope'] ?? 'all') === 'selected');
$selectedCount = $scopeSelected ? count($selectedRules) : count($rules);
$manualPendingStatus = strtolower((string)($state['manual_pending_check_status']['state_value'] ?? ''));
$manualPendingBusy = in_array($manualPendingStatus, ['queued','running'], true);
$pendingTicketCount = (int)($overview['pending'] ?? 0);

function rh(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rstatus(string $status): array
{
    return match ($status) {
        'GREEN' => ['Green', 'green'],
        'RED' => ['Red', 'red'],
        'VOID' => ['Void', 'void'],
        'HALF_GREEN' => ['Half Green', 'half-green'],
        'HALF_RED' => ['Half Red', 'half-red'],
        'REVIEW' => ['Revisão', 'review'],
        default => ['Pendente', 'pending'],
    };
}

function rstateLabel(string $value): string
{
    return match (strtolower(trim($value))) {
        'ok', 'running' => 'Operacional',
        'disabled' => 'Desativado',
        'api_key_missing' => 'Chave da API ausente',
        'error' => 'Com erro',
        '' => 'Sem registro',
        default => ucfirst(str_replace('_', ' ', $value)),
    };
}

function rreviewReason(mixed $details): string
{
    $raw = trim((string)$details);
    if ($raw === '') {
        return 'Motivo não informado.';
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && !empty($decoded['reason'])) {
        return (string)$decoded['reason'];
    }
    return 'Revisão manual necessária.';
}

function rdate(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
    } catch (Throwable) {
        return $raw;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Relatórios · Telegram Router</title>
    <link rel="stylesheet" href="/assets/saas.css"><link rel="stylesheet" href="/assets/brand/navigation-shell.css?v=1">
    <link rel="stylesheet" href="/assets/responsive.css?v=4">
    <link rel="stylesheet" href="/assets/brand/brand.css?v=2">
    <link rel="stylesheet" href="/assets/brand/mobile-shell.css?v=2">
    <link rel="stylesheet" href="/assets/brand/mobile-visual-audit.css?v=17">
    <link rel="stylesheet" href="/assets/brand/mobile-bottom-navigation.css?v=2">
    <link rel="stylesheet" href="/assets/brand/mobile-app-header.css?v=2">
    <link rel="stylesheet" href="/assets/brand/logout-icon.css?v=1">
    <link rel="stylesheet" href="/assets/translation-v2.css?v=2">
<link rel="stylesheet" href="/assets/brand/workers-ai-provider.css?v=5">
<link rel="stylesheet" href="/assets/brand/integrations-v10.css?v=8&workers-dot=5&openai-form=2">
<link rel="stylesheet" href="/assets/brand/activity-status-badges.css?v=4">
<link rel="stylesheet" href="/assets/brand/horizontal-scrollbar.css?v=1"><link rel="stylesheet" href="/assets/brand/project-scrollbar.css?v=1">
<link rel="stylesheet" href="/assets/brand/service-status-responsive.css?v=1">
<link rel="stylesheet" href="/assets/brand/connect-responsive.css?v=2">
<link rel="stylesheet" href="/assets/brand/orchestration-responsive.css?v=1">
<link rel="stylesheet" href="/assets/brand/smart-format.css?v=1">
<link rel="stylesheet" href="/assets/reporting.css?v=10">
<link rel="stylesheet" href="/assets/dialogs.css?v=3">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TMR">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/pwa/apple-touch-icon.png?v=1">
    <script defer src="/assets/pwa.js?v=1"></script>
</head>
<body>
<div class="saas-shell">
    <?php $navActive='reports'; require __DIR__.'/app/project-sidebar.php'; ?>

    <div class="saas-main">
        <header class="saas-topbar">
            <div class="tmr-app-header-brand" aria-label="TelegramRouter">
                <span class="tmr-app-brand-symbol"><img src="/assets/brand/mark.svg?v=1" alt=""></span>
                <span class="tmr-app-brand-name"><b>Telegram<span>Router</span></b><small>Conecte. Direcione. Automatize.</small></span>
            </div>
            <div class="saas-user">
                <span class="saas-avatar">AN</span>
                <span><?=rh($greeting)?></span>
                <?php if ($connectedPhone !== ''): ?><span class="connected-phone">Telegram: <?=rh($connectedPhone)?></span><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="saas-logout" type="submit" aria-label="Sair" title="Sair">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M14 16l4-4-4-4"/><path d="M18 12H8"/></svg>
                    </button>
                </form>
            </div>
        </header>

        <?php $navActive='reports'; require __DIR__.'/app/project-mobile-nav.php'; ?>

        <main class="saas-content reporting-page">
            <?php if ($error): ?><div class="saas-flash error"><?=rh($error)?></div><?php endif; ?>
            <?php if ($notice): ?><div class="saas-flash success"><?=rh($notice)?></div><?php endif; ?>

            <div class="saas-heading reporting-heading">
                <div>
                    <span class="saas-kicker">RELATÓRIOS E PERFORMANCE</span>
                    <h1>Resultados automáticos</h1>
                    <p>Centralize acompanhamento, liquidação, revisão manual e fechamento diário em um único módulo.</p>
                </div>
                <div class="saas-heading-actions">
                    <a class="saas-secondary" href="/?page=dashboard">← Visão geral</a>
                </div>
            </div>

            <nav class="reporting-subnav" aria-label="Seções do módulo de relatórios">
                <a href="#reporting-overview" class="is-active">Visão geral</a>
                <a href="#reporting-config">Configuração</a>
                <a href="#reporting-review">Revisões <span><?=rh(count($reviewLegs))?></span></a>
                <a href="#reporting-history">Histórico</a>
            </nav>

            <section id="reporting-overview" class="overview-status reporting-overview-status <?=$systemEnabled?'is-good':'is-alert'?>">
                <div class="overview-status-icon"><i></i></div>
                <div class="reporting-overview-copy">
                    <span class="saas-kicker">ESTADO DO MÓDULO</span>
                    <h2><?=$systemEnabled?'Relatórios ativos':'Relatórios desativados'?></h2>
                    <p><?=$systemEnabled
                        ? ($scopeSelected ? 'Acompanhando somente as regras selecionadas.' : 'Acompanhando todas as regras de roteamento.')
                        : 'O Router continua funcionando normalmente; apenas o acompanhamento de resultados está desligado.'?></p>
                </div>
                <div class="reporting-overview-meta" aria-label="Estado operacional dos relatórios">
                    <div class="reporting-overview-meta-item">
                        <span>Worker de resultados</span>
                        <strong><?=rh(rstateLabel($workerStatus))?></strong>
                    </div>
                    <div class="reporting-overview-meta-item">
                        <span>Escopo monitorado</span>
                        <strong class="reporting-overview-meta-accent"><?=$scopeSelected?rh($selectedCount).' regras selecionadas':'Todos os canais'?></strong>
                    </div>
                </div>
            </section>

            <section class="saas-metrics reporting-metrics">
                <div class="saas-metric">
                    <div class="saas-metric-label">APOSTAS ACOMPANHADAS <span class="saas-metric-icon">◎</span></div>
                    <strong><?=rh((int)($overview['total'] ?? 0))?></strong>
                    <small><?=rh((int)($overview['pending'] ?? 0))?> pendentes · <?=rh((int)($overview['review_count'] ?? 0))?> em revisão</small>
                </div>
                <div class="saas-metric">
                    <div class="saas-metric-label">GREENS <span class="saas-metric-icon">✓</span></div>
                    <strong class="reporting-good"><?=rh((int)($overview['greens'] ?? 0))?></strong>
                    <small><?=rh((int)($overview['half_greens'] ?? 0))?> half green</small>
                </div>
                <div class="saas-metric">
                    <div class="saas-metric-label">REDS <span class="saas-metric-icon danger">×</span></div>
                    <strong class="metric-danger"><?=rh((int)($overview['reds'] ?? 0))?></strong>
                    <small><?=rh((int)($overview['half_reds'] ?? 0))?> half red · <?=rh((int)($overview['voids'] ?? 0))?> void</small>
                </div>
                <div class="saas-metric">
                    <div class="saas-metric-label">RESULTADO ACUMULADO <span class="saas-metric-icon">↗</span></div>
                    <?php $profit=(float)($overview['profit']??0); ?>
                    <strong class="<?=$profit<0?'metric-danger':'reporting-good'?>"><?=($profit>0?'+':'')?><?=rh(number_format($profit,2,',','.'))?></strong>
                    <small>unidades liquidadas</small>
                </div>
            </section>

            <section class="reporting-ops-strip">
                <div class="reporting-op-item">
                    <span class="reporting-op-dot <?=in_array($workerStatus,['ok','running'],true)?'is-good':($workerStatus==='error'?'is-bad':'')?>"></span>
                    <div><small>WORKER</small><b><?=rh(rstateLabel($workerStatus))?></b></div>
                </div>
                <div class="reporting-op-item">
                    <span class="reporting-op-dot <?=in_array($schedulerStatus,['ok','running'],true)?'is-good':($schedulerStatus==='error'?'is-bad':'')?>"></span>
                    <div><small>SCHEDULER</small><b><?=rh(rstateLabel($schedulerStatus))?></b></div>
                </div>
                <div class="reporting-op-item">
                    <div><small>ESCOPO</small><b><?=$scopeSelected?rh($selectedCount).' regras':'Todos os canais'?></b></div>
                </div>
                <div class="reporting-op-item">
                    <div><small>RELATÓRIO DIÁRIO</small><b><?=!empty($settings['daily_report'])?'Ativo · '.rh(substr((string)$settings['report_time'],0,5)):'Desativado'?></b></div>
                </div>
            </section>

            <section id="reporting-config" class="reporting-section-heading">
                <div>
                    <span class="saas-kicker">CONFIGURAÇÃO</span>
                    <h2>Automação dos relatórios</h2>
                    <p>Configure apenas o necessário. Cada bloco controla uma parte independente do módulo.</p>
                </div>
                <span class="form-status <?=$systemEnabled?'is-configured':'is-empty'?>"><i></i><?=$systemEnabled?'Ativo':'Desativado'?></span>
            </section>

            <form method="post" id="reporting-settings-form" class="reporting-settings-grid">
                <input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>">

                <section class="saas-card reporting-setting-card">
                    <div class="reporting-setting-card-head">
                        <span class="reporting-setting-index">01</span>
                        <div>
                            <h3>Estado do módulo</h3>
                            <p>Controle geral do acompanhamento e dos cálculos.</p>
                        </div>
                    </div>
                    <label class="reporting-switch-row">
                        <input type="checkbox" name="enabled" <?=$systemEnabled?'checked':''?>>
                        <span><b>Ativar sistema de relatórios</b><small>O encaminhamento normal dos cards continua isolado deste módulo.</small></span>
                    </label>
                </section>

                <section class="saas-card reporting-setting-card">
                    <div class="reporting-setting-card-head">
                        <span class="reporting-setting-index">02</span>
                        <div>
                            <h3>Apuração automática</h3>
                            <p>Controle a consulta e a liquidação das apostas.</p>
                        </div>
                    </div>
                    <label class="reporting-switch-row">
                        <input type="checkbox" name="check_results" <?=!empty($settings['check_results'])?'checked':''?>>
                        <span><b>Verificar resultados automaticamente</b><small>Consulta somente quando necessário e liquida com dados confiáveis.</small></span>
                    </label>
                </section>

                <section class="saas-card reporting-setting-card reporting-setting-card-wide">
                    <div class="reporting-setting-card-head">
                        <span class="reporting-setting-index">03</span>
                        <div>
                            <h3>Escopo do acompanhamento</h3>
                            <p>Defina quais regras entram nas estatísticas e liquidações.</p>
                        </div>
                    </div>

                    <div class="reporting-scope-options">
                        <label class="reporting-choice">
                            <input type="radio" name="scope" value="all" <?=!$scopeSelected?'checked':''?>>
                            <span><b>Todos os canais</b><small>Todas as regras elegíveis entram no acompanhamento.</small></span>
                        </label>
                        <label class="reporting-choice">
                            <input type="radio" name="scope" value="selected" <?=$scopeSelected?'checked':''?>>
                            <span><b>Somente selecionados</b><small>Use uma lista controlada de regras.</small></span>
                        </label>
                    </div>

                    <div class="reporting-rules-box" id="reporting-rules-box">
                        <div class="reporting-rules-head">
                            <div><b>Regras disponíveis</b><small><?=rh(count($rules))?> cadastradas</small></div>
                            <span id="reporting-selected-count"><?=rh($selectedCount)?> selecionadas</span>
                        </div>
                        <div class="reporting-rules-list">
                            <?php foreach ($rules as $rule): ?>
                                <?php $checked=!$scopeSelected || in_array((int)$rule['id'],$selectedRules,true); ?>
                                <label class="reporting-rule-option">
                                    <input type="checkbox" name="rules[]" value="<?=rh($rule['id'])?>" <?=$checked?'checked':''?>>
                                    <span class="reporting-rule-main">
                                        <span class="reporting-rule-id">REGRA #<?=rh($rule['id'])?></span>
                                        <b><?=rh($rule['source_chat'])?> <i>→</i> <?=rh($rule['destination_chat'])?></b>
                                        <small><?=trim((string)$rule['trigger_text'])!==''?'Gatilho: '.rh($rule['trigger_text']):'Sem gatilho informado'?></small>
                                    </span>
                                    <span class="reporting-rule-check">✓</span>
                                </label>
                            <?php endforeach; ?>
                            <?php if ($rules === []): ?>
                                <div class="saas-empty">Nenhuma regra de roteamento cadastrada.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="saas-card reporting-setting-card">
                    <div class="reporting-setting-card-head">
                        <span class="reporting-setting-index">04</span>
                        <div>
                            <h3>Relatório diário</h3>
                            <p>Defina horário e destino do resumo consolidado.</p>
                        </div>
                    </div>
                    <label class="reporting-switch-row">
                        <input type="checkbox" name="daily_report" <?=!empty($settings['daily_report'])?'checked':''?>>
                        <span><b>Gerar relatório diário</b><small>O resumo usa o fuso America/Sao_Paulo.</small></span>
                    </label>
                    <div class="reporting-daily-fields">
                        <label>Horário
                            <input type="time" name="report_time" value="<?=rh(substr((string)$settings['report_time'],0,5))?>">
                        </label>
                        <label>Canal específico
                            <input type="text" name="report_chat" value="<?=rh($settings['report_chat'])?>" placeholder="-100… ou @canal">
                        </label>
                    </div>
                </section>

                <section class="saas-card reporting-setting-card reporting-monitor-card">
                    <div class="reporting-setting-card-head">
                        <span class="reporting-setting-index">05</span>
                        <div>
                            <h3>Saúde operacional</h3>
                            <p>Últimos sinais do processamento automático.</p>
                        </div>
                    </div>
                    <div class="reporting-state-list">
                        <?php
                        $stateLabels=[
                            'scheduler_heartbeat_at'=>'Último heartbeat',
                            'worker_last_run_at'=>'Última verificação',
                            'daily_report_last_check_at'=>'Última checagem diária',
                            'worker_last_error'=>'Último erro do worker',
                            'daily_report_last_error'=>'Último erro do relatório',
                        ];
                        foreach($stateLabels as $key=>$label):
                            $value=(string)($state[$key]['state_value']??'');
                            if($value==='' && str_contains($key,'error')) continue;
                        ?>
                            <div class="reporting-state-row">
                                <span><?=$label?></span>
                                <b><?=str_contains($key,'_at')?rh(rdate($value)):rh($value!==''?$value:'Sem registro')?></b>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="reporting-savebar reporting-setting-card-wide">
                    <div>
                        <b>Salvar configurações</b>
                        <span>As alterações passam a valer imediatamente.</span>
                    </div>
                    <button class="saas-primary" type="submit">Salvar alterações</button>
                </div>
            </form>

            <section id="reporting-review" class="reporting-section-heading reporting-section-heading-spaced">
                <div>
                    <span class="saas-kicker">PENDÊNCIAS</span>
                    <h2>Revisão manual</h2>
                    <p>Intervenha somente nas seleções que não puderam ser concluídas automaticamente.</p>
                </div>
                <span class="reporting-review-summary"><span><?=rh(count($reviewLegs))?></span><small><?=count($reviewLegs)===1?'pendência':'pendências'?></small></span>
            </section>

            <?php if ($reviewLegs === []): ?>
                <section class="saas-card reporting-review-empty">
                    <div class="reporting-empty">
                        <span>✓</span>
                        <h3>Nenhuma aposta em revisão</h3>
                        <p>As seleções que exigirem intervenção manual aparecerão aqui.</p>
                    </div>
                </section>
            <?php else: ?>
                <section class="saas-card reporting-review-card">
                    <div class="reporting-review-list">
                        <?php foreach ($reviewLegs as $review): ?>
                            <article class="reporting-review-item">
                                <div class="reporting-review-overview">
                                    <div class="reporting-review-item-head">
                                        <div class="reporting-review-item-id">
                                            <span class="reporting-status-badge review">Revisão</span>
                                            <strong>Ticket #<?=rh($review['ticket_id'])?></strong>
                                            <span>Seleção #<?=rh($review['position_no'])?></span>
                                        </div>
                                        <time><?=rh(rdate($review['placed_at']))?></time>
                                    </div>

                                    <div class="reporting-review-event">
                                        <span>Evento</span>
                                        <strong><?=rh(MatchNameFormatter::normalize((string)$review['match_name']))?></strong>
                                    </div>

                                    <div class="reporting-review-meta-grid">
                                        <div><span>Liga</span><b><?=rh($review['league'] ?: '—')?></b></div>
                                        <div><span>Mercado</span><b><?=rh($review['market_text'] ?: '—')?></b></div>
                                        <div><span>Seleção</span><b><?=rh($review['selection_text'] ?: '—')?></b></div>
                                        <div><span>Odd</span><b><?=rh($review['odds'] ?: '—')?></b></div>
                                    </div>

                                    <div class="reporting-review-reason">
                                        <b>Motivo da revisão</b>
                                        <span><?=rh(rreviewReason($review['settlement_details'] ?? null))?></span>
                                    </div>
                                </div>

                                <aside class="reporting-review-decision-panel">
                                    <div class="reporting-review-decision-head">
                                        <span>DECISÃO MANUAL</span>
                                        <p>Escolha uma ação para esta seleção.</p>
                                    </div>

                                    <form method="post" class="reporting-review-resolve-form" data-dialog-confirm data-dialog-tone="warning">
                                        <input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>">
                                        <input type="hidden" name="action" value="review_resolve">
                                        <input type="hidden" name="leg_id" value="<?=rh($review['id'])?>">
                                        <label>Resultado
                                            <select name="manual_status" required>
                                                <option value="">Selecione</option>
                                                <option value="GREEN">Green</option>
                                                <option value="RED">Red</option>
                                                <option value="VOID">Void</option>
                                                <option value="HALF_GREEN">Half Green</option>
                                                <option value="HALF_RED">Half Red</option>
                                            </select>
                                        </label>
                                        <button type="submit" class="saas-primary reporting-review-apply-btn">Aplicar resultado</button>
                                    </form>

                                    <div class="reporting-review-separator"><span>ou</span></div>

                                    <form method="post" class="reporting-review-reprocess-form">
                                        <input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>">
                                        <input type="hidden" name="action" value="review_reprocess">
                                        <input type="hidden" name="leg_id" value="<?=rh($review['id'])?>">
                                        <button type="submit" class="saas-secondary reporting-review-reprocess-btn">Reprocessar automaticamente</button>
                                    </form>
                                </aside>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section id="reporting-history" class="saas-card reporting-history-card">
                <div class="saas-card-head reporting-history-head">
                    <div>
                        <span class="saas-kicker">HISTÓRICO</span>
                        <h2>Últimas apostas acompanhadas</h2>
                        <p>Os 50 registros mais recentes capturados pelo sistema de resultados.</p>
                    </div>
                    <div class="reporting-history-actions">
                        <span class="reporting-pending-count"><i></i><?=rh($pendingTicketCount)?> pendente<?=$pendingTicketCount===1?'':'s'?></span>
                        <form
                            method="post"
                            class="reporting-force-pending-form"
                            data-dialog-confirm
                            data-dialog-tone="warning"
                            data-dialog-title="Reverificar todas as pendentes?"
                            data-dialog-message="Todas as apostas pendentes serão colocadas na fila de verificação imediatamente."
                            data-dialog-detail="A ação pode consumir consultas da API-Football. Jogos futuros continuam respeitando a janela pós-jogo configurada."
                            data-dialog-confirm-text="Reverificar agora"
                        >
                            <input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>">
                            <input type="hidden" name="action" value="force_pending_check">
                            <button
                                type="submit"
                                class="reporting-force-pending-btn"
                                <?=($pendingTicketCount<=0 || $manualPendingBusy || !$systemEnabled || empty($settings['check_results']))?'disabled':''?>
                                aria-label="Reverificar todas as apostas pendentes"
                            >
                                <svg viewBox="0 0 20 20" aria-hidden="true">
                                    <path d="M15.7 6.2A6.5 6.5 0 1 0 16.4 12M15.7 2.8v3.8h-3.8"/>
                                </svg>
                                <span><?=$manualPendingBusy?'Verificando…':'Reverificar pendentes'?></span>
                            </button>
                        </form>
                        <span class="reporting-history-count"><?=rh(count($tickets))?> exibidas</span>
                    </div>
                </div>

                <?php if ($tickets === []): ?>
                    <div class="reporting-empty">
                        <span>◎</span>
                        <h3>Nenhuma aposta acompanhada ainda</h3>
                        <p>Quando o módulo estiver ativo e uma regra elegível gerar um card, ela aparecerá aqui.</p>
                    </div>
                <?php else: ?>
                    <div class="saas-table-wrap">
                        <table class="saas-table reporting-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Destino</th>
                                    <th>Tipo</th>
                                    <th>Jogo</th>
                                    <th>Mercado</th>
                                    <th>Odd</th>
                                    <th>Status</th>
                                    <th>Resultado</th>
                                    <th>Registrada</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <?php [$statusLabel,$statusClass]=rstatus((string)$ticket['status']); ?>
                                <?php $ticketProfit=(float)$ticket['profit_units']; ?>
                                <?php $historyLegs=is_array($ticket['legs']??null)?$ticket['legs']:[]; ?>
                                <?php $betKind=strtolower(trim((string)($ticket['bet_kind']??''))); ?>
                                <?php $expandable=in_array($betKind,['double','triple','multiple'],true)&&count($historyLegs)>1; ?>
                                <?php $firstLeg=$historyLegs[0]??null; ?>
                                <?php $accordionId='reporting-ticket-'.(int)$ticket['id']; ?>
                                <tr class="<?=$expandable?'reporting-history-parent':''?>" data-ticket-id="<?=rh($ticket['id'])?>">
                                    <td>#<?=rh($ticket['id'])?></td>
                                    <td><?=rh($ticket['destination_chat'])?></td>
                                    <td><span class="reporting-kind"><?=rh(strtoupper((string)$ticket['bet_kind']))?></span></td>
                                    <td class="reporting-history-games">
                                        <?php if($historyLegs===[]): ?>
                                            <span class="reporting-history-empty-value">—</span>
                                        <?php elseif($expandable): ?>
                                            <div class="reporting-history-summary">
                                                <b><?=rh(MatchNameFormatter::normalize((string)($firstLeg['match_name']??'—')))?></b>
                                                <small><?=rh(count($historyLegs))?> jogos neste bilhete</small>
                                                <button
                                                    type="button"
                                                    class="reporting-history-toggle"
                                                    aria-expanded="false"
                                                    aria-controls="<?=rh($accordionId)?>"
                                                    data-history-target="<?=rh($accordionId)?>"
                                                >
                                                    <span>Ver <?=rh(count($historyLegs))?> jogos</span>
                                                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5"/></svg>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach($historyLegs as $leg): ?>
                                                <div class="reporting-history-leg">
                                                    <b><?=rh(MatchNameFormatter::normalize((string)$leg['match_name']))?></b>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="reporting-history-markets">
                                        <?php if($historyLegs===[]): ?>
                                            <span class="reporting-history-empty-value">—</span>
                                        <?php elseif($expandable): ?>
                                            <div class="reporting-history-summary reporting-history-market-summary">
                                                <b><?=rh($firstLeg['market_text']??'—')?></b>
                                                <small><?=rh($firstLeg['selection_text']??'—')?></small>
                                                <em>+<?=rh(max(0,count($historyLegs)-1))?> <?=count($historyLegs)===2?'mercado':'mercados'?></em>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach($historyLegs as $leg): ?>
                                                <div class="reporting-history-leg reporting-history-market">
                                                    <span><b><?=rh($leg['market_text'])?></b><small><?=rh($leg['selection_text'])?></small></span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?=rh($ticket['total_odds'] ?? '—')?></td>
                                    <td>
                                        <button
                                            type="button"
                                            class="reporting-status-badge reporting-status-edit <?=$statusClass?>"
                                            data-ticket-id="<?=rh($ticket['id'])?>"
                                            data-ticket-status="<?=rh(strtoupper((string)$ticket['status']))?>"
                                            data-ticket-label="#<?=rh($ticket['id'])?> · <?=rh(strtoupper((string)$ticket['bet_kind']))?>"
                                            title="Clique para alterar o status"
                                            aria-label="Alterar status da aposta #<?=rh($ticket['id'])?>. Status atual: <?=rh($statusLabel)?>"
                                        ><?=$statusLabel?><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg></button>
                                    </td>
                                    <td class="<?=$ticketProfit<0?'reporting-negative':'reporting-positive'?>"><?=($ticketProfit>0?'+':'')?><?=rh(number_format($ticketProfit,2,',','.'))?> un.</td>
                                    <td><?=rh(rdate($ticket['placed_at']))?></td>
                                </tr>

                                <?php if($expandable): ?>
                                    <tr id="<?=rh($accordionId)?>" class="reporting-history-accordion-row" hidden>
                                        <td colspan="9">
                                            <div class="reporting-history-accordion">
                                                <div class="reporting-history-accordion-head">
                                                    <div>
                                                        <span class="saas-kicker">SELEÇÕES DO BILHETE</span>
                                                        <b><?=rh(strtoupper((string)$ticket['bet_kind']))?> · <?=rh(count($historyLegs))?> pernas</b>
                                                    </div>
                                                    <small>Odd total <?=rh($ticket['total_odds'] ?? '—')?></small>
                                                </div>
                                                <div class="reporting-history-accordion-list">
                                                    <?php foreach($historyLegs as $leg): ?>
                                                        <?php [$legStatusLabel,$legStatusClass]=rstatus((string)($leg['status']??'PENDING')); ?>
                                                        <article class="reporting-history-accordion-leg">
                                                            <span class="reporting-history-leg-no"><?=rh($leg['position_no'])?></span>
                                                            <div class="reporting-history-accordion-field reporting-history-accordion-game">
                                                                <small>JOGO</small>
                                                                <b><?=rh($leg['match_name'])?></b>
                                                            </div>
                                                            <div class="reporting-history-accordion-field reporting-history-accordion-market">
                                                                <small>MERCADO</small>
                                                                <b><?=rh($leg['market_text'])?></b>
                                                                <span><?=rh($leg['selection_text'])?></span>
                                                            </div>
                                                            <div class="reporting-history-accordion-field reporting-history-accordion-odd">
                                                                <small>ODD</small>
                                                                <b><?=rh($leg['odds'] ?? '—')?></b>
                                                            </div>
                                                            <div class="reporting-history-accordion-field reporting-history-accordion-status">
                                                                <small>STATUS</small>
                                                                <span class="reporting-status-badge <?=$legStatusClass?>"><?=$legStatusLabel?></span>
                                                            </div>
                                                        </article>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<div id="reporting-status-editor" class="tmr-dialog-overlay reporting-status-editor-overlay" hidden>
    <section class="tmr-dialog reporting-status-editor-dialog" data-tone="primary" role="dialog" aria-modal="true" aria-labelledby="reporting-status-editor-title">
        <div class="tmr-dialog-head">
            <div class="tmr-dialog-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 12h16M12 4v16"/></svg>
            </div>
            <div class="tmr-dialog-copy">
                <span class="tmr-dialog-kicker">STATUS MANUAL</span>
                <h2 class="tmr-dialog-title" id="reporting-status-editor-title">Alterar status da aposta</h2>
            </div>
            <button type="button" class="tmr-dialog-close reporting-status-editor-close" aria-label="Fechar">×</button>
        </div>
        <form method="post" class="reporting-status-editor-form">
            <input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>">
            <input type="hidden" name="action" value="history_status_update">
            <input type="hidden" name="ticket_id" id="reporting-status-ticket-id" value="">
            <div class="tmr-dialog-body">
                <p class="tmr-dialog-message">Escolha o novo status. A alteração manual passa a prevalecer sobre a liquidação automática até você reabrir a aposta como Pendente.</p>
                <div class="reporting-status-editor-ticket" id="reporting-status-ticket-label"></div>
                <div class="reporting-status-editor-options" role="radiogroup" aria-label="Novo status">
                    <?php foreach ([
                        'PENDING'=>['Pendente','pending'],
                        'GREEN'=>['Green','green'],
                        'RED'=>['Red','red'],
                        'VOID'=>['Void','void'],
                        'HALF_GREEN'=>['Half Green','half-green'],
                        'HALF_RED'=>['Half Red','half-red'],
                        'REVIEW'=>['Revisão','review'],
                    ] as $editorStatus=>$editorMeta): ?>
                        <label class="reporting-status-editor-option">
                            <input type="radio" name="manual_status" value="<?=rh($editorStatus)?>" required>
                            <span class="reporting-status-badge <?=$editorMeta[1]?>"><?=$editorMeta[0]?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="tmr-dialog-note reporting-status-editor-note">
                    <svg viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01"/><circle cx="12" cy="12" r="9"/></svg>
                    <span>Ao escolher Pendente, a aposta volta para a fila automática de verificação.</span>
                </div>
            </div>
            <div class="tmr-dialog-actions">
                <button type="button" class="tmr-dialog-cancel reporting-status-editor-cancel">Cancelar</button>
                <button type="submit" class="tmr-dialog-confirm">Salvar status</button>
            </div>
        </form>
    </section>
</div>

<script>
(()=>{
    const getBox=()=>document.getElementById('reporting-rules-box');
    const getCount=()=>document.getElementById('reporting-selected-count');
    const getRuleChecks=()=>{
        const box=getBox();
        return box ? [...box.querySelectorAll('input[name="rules[]"]')] : [];
    };
    const isAllScope=()=>document.querySelector('input[name="scope"]:checked')?.value==='all';

    const updateCount=()=>{
        const count=getCount();
        if(!count) return;
        const ruleChecks=getRuleChecks();
        const total=ruleChecks.filter(input=>input.checked).length;
        count.textContent=total+' selecionada'+(total===1?'':'s');
    };

    const selectAllRules=()=>{
        getRuleChecks().forEach(input=>{
            input.checked=true;
            input.setAttribute('checked','checked');
        });
        updateCount();
    };

    const syncReportingScope=()=>{
        const box=getBox();
        if(!box) return;

        const all=isAllScope();
        if(all) selectAllRules();

        box.classList.toggle('is-disabled',all);
        box.setAttribute('aria-disabled',all?'true':'false');

        getRuleChecks().forEach(input=>{
            input.disabled=all;
        });

        updateCount();
    };

    document.addEventListener('change',(event)=>{
        const target=event.target;
        const settingsForm=document.getElementById('reporting-settings-form');
        if(settingsForm && target instanceof Element && settingsForm.contains(target)){
            settingsForm.dataset.dirty='1';
        }

        if(target instanceof HTMLInputElement && target.name==='scope'){
            if(target.value==='all' && target.checked){
                selectAllRules();
            }
            syncReportingScope();
            return;
        }

        if(target instanceof HTMLInputElement && target.name==='rules[]'){
            if(isAllScope() && !target.checked){
                target.checked=true;
                target.setAttribute('checked','checked');
            }
            updateCount();
        }
    });

    const statusEditor=document.getElementById('reporting-status-editor');
    const statusTicketId=document.getElementById('reporting-status-ticket-id');
    const statusTicketLabel=document.getElementById('reporting-status-ticket-label');

    const closeStatusEditor=()=>{
        if(!(statusEditor instanceof HTMLElement)) return;
        statusEditor.hidden=true;
        document.body.classList.remove('tmr-dialog-lock');
    };

    const openStatusEditor=(button)=>{
        if(!(statusEditor instanceof HTMLElement) || !(statusTicketId instanceof HTMLInputElement)) return;

        const ticketId=button.dataset.ticketId||'';
        const current=(button.dataset.ticketStatus||'PENDING').toUpperCase();
        statusTicketId.value=ticketId;
        if(statusTicketLabel) statusTicketLabel.textContent=button.dataset.ticketLabel||('#'+ticketId);

        statusEditor.querySelectorAll('input[name="manual_status"]').forEach(input=>{
            if(input instanceof HTMLInputElement) input.checked=input.value===current;
        });

        statusEditor.hidden=false;
        document.body.classList.add('tmr-dialog-lock');
        requestAnimationFrame(()=>{
            const checked=statusEditor.querySelector('input[name="manual_status"]:checked');
            if(checked instanceof HTMLInputElement) checked.focus();
        });
    };

    document.addEventListener('keydown',(event)=>{
        if(event.key==='Escape' && statusEditor instanceof HTMLElement && !statusEditor.hidden){
            event.preventDefault();
            closeStatusEditor();
        }
    });

    statusEditor?.addEventListener('click',(event)=>{
        const target=event.target;
        if(target===statusEditor || (target instanceof Element && target.closest('.reporting-status-editor-close,.reporting-status-editor-cancel'))){
            closeStatusEditor();
        }
    });

    statusEditor?.querySelector('.reporting-status-editor-form')?.addEventListener('submit',()=>{
        closeStatusEditor();
    });

    document.addEventListener('click',(event)=>{
        const target=event.target;

        const statusButton=target instanceof Element ? target.closest('.reporting-status-edit') : null;
        if(statusButton instanceof HTMLButtonElement){
            openStatusEditor(statusButton);
            return;
        }

        const historyToggle=target instanceof Element ? target.closest('.reporting-history-toggle') : null;
        if(historyToggle instanceof HTMLButtonElement){
            const rowId=historyToggle.dataset.historyTarget||'';
            const detailRow=rowId!==''?document.getElementById(rowId):null;
            if(detailRow instanceof HTMLTableRowElement){
                const expanded=historyToggle.getAttribute('aria-expanded')==='true';
                historyToggle.setAttribute('aria-expanded',expanded?'false':'true');
                detailRow.hidden=expanded;
                historyToggle.closest('tr')?.classList.toggle('is-expanded',!expanded);
                const label=historyToggle.querySelector('span');
                if(label){
                    const count=detailRow.querySelectorAll('.reporting-history-accordion-leg').length;
                    label.textContent=expanded?'Ver '+count+' jogos':'Ocultar jogos';
                }
            }
            return;
        }

        const choice=target instanceof Element ? target.closest('.reporting-choice') : null;
        if(!choice) return;

        const radio=choice.querySelector('input[name="scope"]');
        if(!(radio instanceof HTMLInputElement)) return;

        if(!radio.checked){
            radio.checked=true;
            radio.dispatchEvent(new Event('change',{bubbles:true}));
        }else if(radio.value==='all'){
            selectAllRules();
            syncReportingScope();
        }
    });

    document.addEventListener('submit',(event)=>{
        const form=event.target;
        if(!(form instanceof HTMLFormElement) || form.id!=='reporting-settings-form') return;

        if(isAllScope()){
            // Inputs disabled are not submitted by FormData. Re-enable only at submit
            // while keeping every rule selected.
            getRuleChecks().forEach(input=>{
                input.checked=true;
                input.disabled=false;
            });
        }
    },true);

    const init=()=>syncReportingScope();

    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded',init,{once:true});
    }else{
        init();
    }

    window.addEventListener('painel-atualizado',init);
})();
</script>
<script src="/assets/dialogs.js?v=3" defer></script>
<script src="/assets/live.js?v=3" defer></script>
<script src="/assets/toast.js" defer></script>
</body>
</html>
