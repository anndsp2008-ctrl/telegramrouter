<?php declare(strict_types=1);

require __DIR__ . '/../app/Reporting/TicketNormalizer.php';
require __DIR__ . '/../app/Reporting/SettlementEngine.php';

use App\Reporting\SettlementEngine;
use App\Reporting\TicketNormalizer;

function assertTrue(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    echo "OK {$label}\n";
}

function settle(array $leg, array $stats, string $status, string $label): void
{
    $result = SettlementEngine::settle($leg, $stats);
    assertTrue(($result['status'] ?? '') === $status, $label . ' status=' . ($result['status'] ?? ''));
}

$ticket = TicketNormalizer::fromModel([
    'kind'=>'simple',
    'odd'=>'1.60',
    'bookmaker'=>'Stake',
    'legs'=>[[
        'sport'=>'Futebol',
        'match'=>'Manchester City x Arsenal',
        'league'=>'Premier League',
        'date'=>'27/09/2026',
        'market'=>'Total de escanteios',
        'selection'=>'Mais de 8,5 escanteios',
        'odd'=>'1.60',
    ]],
]);

assertTrue(is_array($ticket), 'normaliza aposta simples');
assertTrue(($ticket['legs'][0]['market_key'] ?? '') === 'corners_total', 'detecta mercado de escanteios');
assertTrue(($ticket['legs'][0]['side'] ?? '') === 'over', 'detecta over');
assertTrue(abs((float)($ticket['legs'][0]['line'] ?? 0) - 8.5) < 0.0001, 'detecta linha 8.5');
assertTrue(($ticket['legs'][0]['event_date'] ?? '') === '2026-09-27', 'normaliza data');
assertTrue(abs((float)($ticket['total_odds'] ?? 0) - 1.60) < 0.0001, 'preserva odd');

settle(
    ['market_key'=>'corners_total','side'=>'over','line_value'=>8.5,'odds'=>1.60],
    ['corners_home'=>5,'corners_away'=>5],
    SettlementEngine::GREEN,
    'over 8.5 com 10 escanteios'
);

settle(
    ['market_key'=>'corners_total','side'=>'over','line_value'=>8.5,'odds'=>1.60],
    ['fixture_status'=>'AET','corners_home'=>5,'corners_away'=>5],
    SettlementEngine::REVIEW,
    'escanteios com prorrogacao vao para revisao'
);

settle(
    ['market_key'=>'corners_total','side'=>'over','line_value'=>8.5,'odds'=>1.60],
    ['corners_home'=>4,'corners_away'=>4],
    SettlementEngine::RED,
    'over 8.5 com 8 escanteios'
);

settle(
    ['market_key'=>'corners_total','side'=>'over','line_value'=>8.0,'odds'=>1.70],
    ['corners_home'=>4,'corners_away'=>4],
    SettlementEngine::VOID,
    'over 8.0 push'
);

settle(
    ['market_key'=>'corners_total','side'=>'over','line_value'=>8.25,'odds'=>1.80],
    ['corners_home'=>4,'corners_away'=>4],
    SettlementEngine::HALF_RED,
    'over 8.25 com 8 escanteios'
);

settle(
    ['market_key'=>'corners_total','side'=>'over','line_value'=>8.75,'odds'=>1.80],
    ['corners_home'=>5,'corners_away'=>4],
    SettlementEngine::HALF_GREEN,
    'over 8.75 com 9 escanteios'
);

settle(
    ['market_key'=>'goals_total','side'=>'under','line_value'=>2.5,'odds'=>1.90],
    ['goals_home'=>1,'goals_away'=>1],
    SettlementEngine::GREEN,
    'under 2.5 gols'
);

settle(
    ['market_key'=>'btts','side'=>'yes','odds'=>1.70],
    ['goals_home'=>2,'goals_away'=>1],
    SettlementEngine::GREEN,
    'ambas marcam sim'
);

settle(
    ['market_key'=>'match_result','side'=>'home','odds'=>1.75],
    ['goals_home'=>2,'goals_away'=>0],
    SettlementEngine::GREEN,
    'vencedor mandante'
);

settle(
    ['market_key'=>'cards_total','side'=>'over','line_value'=>4.5,'odds'=>1.80],
    ['yellow_cards_home'=>2,'yellow_cards_away'=>3,'red_cards_home'=>0,'red_cards_away'=>0],
    SettlementEngine::REVIEW,
    'cartoes genericos dependem da regra da casa'
);

$teamTotal = TicketNormalizer::fromModel([
    'kind'=>'simple',
    'odd'=>'1.90',
    'legs'=>[[
        'sport'=>'Futebol',
        'match'=>'Barcelona x Sevilla',
        'market'=>'Gols',
        'selection'=>'Barcelona mais de 1,5 gols',
        'odd'=>'1.90',
    ]],
]);
assertTrue(($teamTotal['legs'][0]['market_key'] ?? '') === 'team_goals_total', 'detecta total de gols por equipe');
assertTrue(($teamTotal['legs'][0]['target_team'] ?? '') === 'Barcelona', 'detecta equipe alvo');

$abbreviatedTeam = TicketNormalizer::fromModel([
    'kind'=>'simple',
    'odd'=>'1.85',
    'legs'=>[[
        'sport'=>'Futebol',
        'match'=>'Manchester City x Arsenal',
        'market'=>'Gols',
        'selection'=>'Man City mais de 1,5 gols',
        'odd'=>'1.85',
    ]],
]);
assertTrue(($abbreviatedTeam['legs'][0]['market_key'] ?? '') === 'team_goals_total', 'resolve abreviacao de equipe');
assertTrue(($abbreviatedTeam['legs'][0]['target_team'] ?? '') === 'Manchester City', 'mapeia abreviacao para lado correto');

$unknownTeam = TicketNormalizer::fromModel([
    'kind'=>'simple',
    'odd'=>'1.85',
    'legs'=>[[
        'sport'=>'Futebol',
        'match'=>'Manchester City x Arsenal',
        'market'=>'Gols',
        'selection'=>'United mais de 1,5 gols',
        'odd'=>'1.85',
    ]],
]);
assertTrue(($unknownTeam['legs'][0]['market_key'] ?? '') === 'unsupported', 'equipe ambigua nao vira total da partida');

settle(
    ['market_key'=>'unsupported','side'=>'','odds'=>1.50],
    [],
    SettlementEngine::REVIEW,
    'mercado desconhecido vai para revisão'
);

$multiple = TicketNormalizer::fromModel([
    'kind'=>'multiple',
    'odd'=>'2.55',
    'legs'=>[
        [
            'sport'=>'Futebol',
            'match'=>'Barcelona x Sevilla',
            'league'=>'La Liga',
            'date'=>'2026-09-27',
            'market'=>'Resultado da partida',
            'selection'=>'Barcelona',
            'odd'=>'1.50',
        ],
        [
            'sport'=>'Futebol',
            'match'=>'Inter x Milan',
            'league'=>'Serie A',
            'date'=>'2026-09-27',
            'market'=>'Total de gols',
            'selection'=>'Mais de 2.5 gols',
            'odd'=>'1.70',
        ],
    ],
]);

assertTrue(is_array($multiple) && count($multiple['legs']) === 2, 'preserva todas as legs da múltipla');
assertTrue(($multiple['legs'][0]['market_key'] ?? '') === 'match_result', 'normaliza resultado da partida');
assertTrue(($multiple['legs'][1]['market_key'] ?? '') === 'goals_total', 'normaliza total de gols');

echo "REPORTING_SETTLEMENT_SMOKE_PASSED\n";
