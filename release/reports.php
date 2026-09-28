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
        if (($_POST['action'] ?? '') === 'logout') {
            Auth::logout();
            header('Location:/login.php');
            exit;
        }

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
    <link rel="stylesheet" href="/assets/saas.css">
    <link rel="stylesheet" href="/assets/responsive.css?v=4">
    <link rel="stylesheet" href="/assets/brand/brand.css?v=2">
    <link rel="stylesheet" href="/assets/brand/mobile-shell.css?v=2">
    <link rel="stylesheet" href="/assets/brand/mobile-visual-audit.css?v=17">
    <link rel="stylesheet" href="/assets/brand/mobile-bottom-navigation.css?v=2">
    <link rel="stylesheet" href="/assets/brand/mobile-app-header.css?v=2">
    <link rel="stylesheet" href="/assets/brand/logout-icon.css?v=1">
    <link rel="stylesheet" href="/assets/reporting.css?v=1">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TMR">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/pwa/apple-touch-icon.png?v=1">
    <script defer src="/assets/pwa.js?v=1"></script>
</head>
<body>
<div class="saas-shell">
    <aside class="saas-sidebar">
        <div class="saas-brand">
            <span class="saas-logo brand-mark"><img src="/assets/brand/mark.svg?v=1" alt="" aria-hidden="true"></span>
            <div><b>Telegram Router</b><small>Automação inteligente</small></div>
        </div>

        <div class="saas-nav-label">PAINEL</div>
        <nav class="saas-nav">
            <a href="/?page=dashboard"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/></svg></span>Visão geral</a>
            <a href="/?page=rules"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v10a2 2 0 0 0 2 2h4M18 17V7a2 2 0 0 0-2-2h-4m-2 12 2 2-2 2m4-18-2 2 2 2"/></svg></span>Regras de roteamento</a>
            <a href="/ai-learning.php"><span class="nav-icon">✦</span>Aprendizado da IA</a>
            <a href="/?page=events"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>Atividade</a>
            <a href="/?page=integrations"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 3v5m6-5v5M7 8h10v4a5 5 0 0 1-10 0ZM12 17v4"/></svg></span>Integrações</a>
            <a href="/connect.php"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m21 3-7 18-4-7-7-4Zm0 0L10 14"/></svg></span>Telegram</a>
            <a class="active" href="/reports.php"><span class="nav-icon"><svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/></svg></span>Relatórios</a>
        </nav>

        <div class="saas-bottom">
            <div class="saas-status"><i></i>Serviço protegido</div>
        </div>
    </aside>

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

        <nav class="tmr-mobile-navigation" aria-label="Navegação principal em celulares e tablets">
            <a href="/?page=dashboard"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg></span><span>Visão geral</span></a>
            <a href="/?page=rules"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01" stroke-width="3"/></svg></span><span>Regras</span></a>
            <a class="tmr-nav-create" href="/?page=rules#nova-regra" aria-label="Criar nova regra"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span><span>Nova regra</span></a>
            <a href="/?page=events"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></span><span>Atividade</span></a>
            <details class="tmr-nav-more">
                <summary aria-label="Mais módulos"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg></span><span>Mais</span></summary>
                <div class="tmr-more-panel">
                    <a class="is-active" aria-current="page" href="/reports.php"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/></svg></span><span>Relatórios</span></a>
                    <a href="/?page=integrations"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3v6M16 3v6M6 9h12v3a6 6 0 0 1-12 0V9zM12 18v3"/></svg></span><span>Integrações</span></a>
                    <a href="/connect.php"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 7a7 7 0 0 1 10 0M4 4a11 11 0 0 1 16 0M10 10a3 3 0 0 1 4 0"/><circle cx="12" cy="15" r="1.5"/></svg></span><span>Conectar Telegram</span></a>
                    <a href="/ai-learning.php"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3l1.8 6.2L20 11l-6.2 1.8L12 19l-1.8-6.2L4 11l6.2-1.8L12 3z"/><path d="M19 17v4M17 19h4"/></svg></span><span>Aprendizado da IA</span></a>
                    <a href="/reset.php"><span class="tmr-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v6h6"/><path d="M12 8v4M12 16h.01"/></svg></span><span>Reset de dados</span></a>
                </div>
            </details>
        </nav>

        <main class="saas-content reporting-page">
            <?php if ($error): ?><div class="saas-flash error"><?=rh($error)?></div><?php endif; ?>
            <?php if ($notice): ?><div class="saas-flash success"><?=rh($notice)?></div><?php endif; ?>

            <div class="saas-heading reporting-heading">
                <div>
                    <span class="saas-kicker">RELATÓRIOS E PERFORMANCE</span>
                    <h1>Resultados automáticos</h1>
                    <p>Acompanhe liquidações, escolha quais canais entram no cálculo e controle o relatório diário.</p>
                </div>
                <div class="saas-heading-actions">
                    <a class="saas-secondary" href="/?page=dashboard">← Visão geral</a>
                </div>
            </div>

            <section class="overview-status <?=$systemEnabled?'is-good':'is-alert'?> reporting-status">
                <div class="overview-status-icon"><i></i></div>
                <div>
                    <span class="saas-kicker">ESTADO DO MÓDULO</span>
                    <h2><?=$systemEnabled?'Relatórios ativos':'Relatórios desativados'?></h2>
                    <p><?=$systemEnabled
                        ? ($scopeSelected ? 'Acompanhando somente as regras selecionadas.' : 'Acompanhando todas as regras de roteamento.')
                        : 'O Router continua funcionando normalmente; apenas o acompanhamento de resultados está desligado.'?></p>
                </div>
                <div class="overview-status-meta">
                    <span>Worker de resultados</span>
                    <b><?=rh(rstateLabel($workerStatus))?></b>
                    <span class="reporting-scope-chip"><?=$scopeSelected?rh($selectedCount).' selecionadas':'Todos os canais'?></span>
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

            <div class="reporting-layout">
                <section class="saas-card reporting-config-card">
                    <div class="saas-card-head">
                        <div>
                            <span class="saas-kicker">CONFIGURAÇÃO</span>
                            <h2>Automação dos relatórios</h2>
                            <p>Defina o escopo, a apuração e o destino do resumo diário.</p>
                        </div>
                        <span class="form-status <?=$systemEnabled?'is-configured':'is-empty'?>"><i></i><?=$systemEnabled?'Ativo':'Desativado'?></span>
                    </div>

                    <form method="post" id="reporting-settings-form">
                        <input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>">

                        <div class="reporting-section">
                            <div class="reporting-section-title">
                                <span class="reporting-section-number">01</span>
                                <div><b>Estado do módulo</b><small>Controle geral do acompanhamento e dos cálculos.</small></div>
                            </div>
                            <label class="reporting-switch-row">
                                <input type="checkbox" name="enabled" <?=$systemEnabled?'checked':''?>>
                                <span><b>Ativar sistema de relatórios</b><small>Não altera o fluxo de encaminhamento dos cards.</small></span>
                            </label>
                        </div>

                        <div class="reporting-section">
                            <div class="reporting-section-title">
                                <span class="reporting-section-number">02</span>
                                <div><b>Escopo do acompanhamento</b><small>Escolha se todas as regras ou apenas algumas entram nos resultados.</small></div>
                            </div>

                            <div class="reporting-scope-options">
                                <label class="reporting-choice">
                                    <input type="radio" name="scope" value="all" <?=!$scopeSelected?'checked':''?>>
                                    <span><b>Todos os canais</b><small>Toda regra ativa poderá entrar no acompanhamento.</small></span>
                                </label>
                                <label class="reporting-choice">
                                    <input type="radio" name="scope" value="selected" <?=$scopeSelected?'checked':''?>>
                                    <span><b>Somente selecionados</b><small>Apenas as regras marcadas abaixo serão consideradas.</small></span>
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
                        </div>

                        <div class="reporting-section">
                            <div class="reporting-section-title">
                                <span class="reporting-section-number">03</span>
                                <div><b>Apuração e envio</b><small>Configure a liquidação automática e o horário do resumo.</small></div>
                            </div>

                            <div class="reporting-toggle-grid">
                                <label class="reporting-switch-row">
                                    <input type="checkbox" name="check_results" <?=!empty($settings['check_results'])?'checked':''?>>
                                    <span><b>Verificar resultados automaticamente</b><small>Consulta as partidas pendentes e liquida quando houver dados confiáveis.</small></span>
                                </label>
                                <label class="reporting-switch-row">
                                    <input type="checkbox" name="daily_report" <?=!empty($settings['daily_report'])?'checked':''?>>
                                    <span><b>Gerar relatório diário</b><small>Cria o resumo consolidado no horário configurado.</small></span>
                                </label>
                            </div>

                            <div class="saas-form-grid reporting-fields">
                                <label>Horário do relatório
                                    <span class="field-help">Fuso fixo: America/Sao_Paulo.</span>
                                    <input type="time" name="report_time" value="<?=rh(substr((string)$settings['report_time'],0,5))?>">
                                </label>
                                <label class="field-wide">Canal específico do relatório
                                    <span class="field-help">Deixe vazio para cada destino receber apenas o próprio resumo.</span>
                                    <input type="text" name="report_chat" value="<?=rh($settings['report_chat'])?>" placeholder="-100… ou @canal">
                                </label>
                            </div>
                        </div>

                        <div class="saas-actions reporting-actions">
                            <span class="form-note">As alterações passam a valer imediatamente após salvar.</span>
                            <button class="saas-primary" type="submit">Salvar configurações →</button>
                        </div>
                    </form>
                </section>

                <section class="saas-card reporting-worker-card">
                    <div class="saas-card-head">
                        <div>
                            <span class="saas-kicker">MONITORAMENTO</span>
                            <h2>Estado do processamento</h2>
                            <p>Saúde do scheduler e das rotinas automáticas do módulo.</p>
                        </div>
                    </div>

                    <div class="reporting-worker-overview">
                        <div class="reporting-worker-status <?=in_array($workerStatus,['ok','running'],true)?'is-good':($workerStatus==='error'?'is-bad':'')?>">
                            <i></i>
                            <div><span>WORKER</span><b><?=rh(rstateLabel($workerStatus))?></b></div>
                        </div>
                        <div class="reporting-worker-status <?=in_array($schedulerStatus,['ok','running'],true)?'is-good':($schedulerStatus==='error'?'is-bad':'')?>">
                            <i></i>
                            <div><span>SCHEDULER</span><b><?=rh(rstateLabel($schedulerStatus))?></b></div>
                        </div>
                    </div>

                    <div class="reporting-state-list">
                        <?php
                        $stateLabels=[
                            'scheduler_heartbeat_at'=>'Último heartbeat',
                            'worker_last_run_at'=>'Última verificação de resultados',
                            'daily_report_last_check_at'=>'Última checagem do relatório diário',
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

                    <div class="reporting-worker-note">
                        <span>Proteção operacional</span>
                        <p>Falhas neste módulo são isoladas e não interrompem o encaminhamento normal das mensagens.</p>
                    </div>
                </section>
            </div>

            <section class="saas-card reporting-history-card">
                <div class="saas-card-head">
                    <div>
                        <span class="saas-kicker">HISTÓRICO</span>
                        <h2>Últimas apostas acompanhadas</h2>
                        <p>Os 50 registros mais recentes capturados pelo sistema de resultados.</p>
                    </div>
                    <span class="reporting-history-count"><?=rh(count($tickets))?> exibidas</span>
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
                                <tr>
                                    <td>#<?=rh($ticket['id'])?></td>
                                    <td><?=rh($ticket['destination_chat'])?></td>
                                    <td><span class="reporting-kind"><?=rh(strtoupper((string)$ticket['bet_kind']))?></span></td>
                                    <td><?=rh($ticket['total_odds'] ?? '—')?></td>
                                    <td><span class="reporting-status-badge <?=$statusClass?>"><?=$statusLabel?></span></td>
                                    <td class="<?=$ticketProfit<0?'reporting-negative':'reporting-positive'?>"><?=($ticketProfit>0?'+':'')?><?=rh(number_format($ticketProfit,2,',','.'))?> un.</td>
                                    <td><?=rh(rdate($ticket['placed_at']))?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
    const radios=[...document.querySelectorAll('input[name="scope"]')];
    const box=document.getElementById('reporting-rules-box');
    const count=document.getElementById('reporting-selected-count');
    const ruleChecks=box?[...box.querySelectorAll('input[name="rules[]"]')]:[];

    const updateCount=()=>{
        if(!count)return;
        const total=ruleChecks.filter(input=>input.checked).length;
        count.textContent=total+' selecionada'+(total===1?'':'s');
    };

    const selectAllRules=()=>{
        ruleChecks.forEach(input=>{ input.checked=true; });
        updateCount();
    };

    const isAllScope=()=>document.querySelector('input[name="scope"]:checked')?.value==='all';

    const sync=()=>{
        const selected=!isAllScope();
        if(isAllScope())selectAllRules();
        if(box){
            box.classList.toggle('is-disabled',!selected);
            box.setAttribute('aria-disabled',selected?'false':'true');
        }
        updateCount();
    };

    radios.forEach(radio=>{
        const apply=()=>{
            if(radio.value==='all' && radio.checked)selectAllRules();
            sync();
        };
        radio.addEventListener('change',apply);
        radio.addEventListener('click',apply);
    });

    ruleChecks.forEach(input=>input.addEventListener('change',()=>{
        if(isAllScope() && !input.checked){
            input.checked=true;
        }
        updateCount();
    }));

    const form=document.getElementById('reporting-settings-form');
    if(form){
        form.addEventListener('submit',()=>{
            if(isAllScope())selectAllRules();
        });
    }

    if(isAllScope())selectAllRules();
    sync();
});
</script>
<script src="/assets/live.js" defer></script>
<script src="/assets/toast.js" defer></script>
</body>
</html>
