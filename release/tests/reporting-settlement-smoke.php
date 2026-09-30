<?php declare(strict_types=1);

require __DIR__ . '/../app/Reporting/TicketNormalizer.php';
require __DIR__ . '/../app/Reporting/FootballResultsClient.php';
require __DIR__ . '/../app/Reporting/ApiFootballClient.php';
require __DIR__ . '/../app/Reporting/FootballDataClient.php';
require __DIR__ . '/../app/Reporting/SettlementEngine.php';
require __DIR__ . '/../app/Reporting/FixtureMatcher.php';
require __DIR__ . '/../app/Reporting/ResultWorker.php';

use App\Reporting\SettlementEngine;
use App\Reporting\TicketNormalizer;
use App\Reporting\ResultWorker;

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

$searchTerm = new ReflectionMethod(\App\Reporting\ApiFootballClient::class, 'searchTerm');
$searchTerm->setAccessible(true);
assertTrue($searchTerm->invoke(null, 'Atlético-MG (BRA)') === 'Atletico MG BRA', 'normaliza acentos, hifen e parenteses para /teams?search');
assertTrue($searchTerm->invoke(null, "Paris Saint-Germain FC") === 'Paris Saint Germain FC', 'normaliza pontuacao no nome do time');
assertTrue($searchTerm->invoke(null, 'São Paulo') === 'Sao Paulo', 'normaliza caracteres unicode para busca da API');

$footballDataStatus = new ReflectionMethod(\App\Reporting\FootballDataClient::class, 'statusShort');
$footballDataStatus->setAccessible(true);
assertTrue($footballDataStatus->invoke(null, 'FINISHED') === 'FT', 'football-data normaliza FINISHED para FT');
assertTrue($footballDataStatus->invoke(null, 'SCHEDULED') === 'NS', 'football-data normaliza SCHEDULED para NS');
assertTrue($footballDataStatus->invoke(null, 'EXTRA_TIME') === 'ET', 'football-data preserva prorrogacao como estado em andamento');

$footballDataNormalize = new ReflectionMethod(\App\Reporting\FootballDataClient::class, 'normalizeMatch');
$footballDataNormalize->setAccessible(true);
$footballDataFixture = $footballDataNormalize->invoke(null, [
    'id'=>987654,
    'utcDate'=>'2026-09-27T19:00:00Z',
    'status'=>'FINISHED',
    'competition'=>['id'=>2021,'name'=>'Premier League'],
    'homeTeam'=>['id'=>10,'name'=>'Manchester City'],
    'awayTeam'=>['id'=>11,'name'=>'Arsenal'],
    'score'=>['fullTime'=>['home'=>2,'away'=>1]],
]);
assertTrue(($footballDataFixture['fixture']['id'] ?? 0) === 987654, 'football-data preserva id numerico');
assertTrue(($footballDataFixture['teams']['home']['name'] ?? '') === 'Manchester City', 'football-data normaliza mandante');
assertTrue(($footballDataFixture['score']['fulltime']['home'] ?? null) === 2.0, 'football-data normaliza placar final');

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
assertTrue(($ticket['legs'][0]['match'] ?? '') === 'Manchester City vs Arsenal', 'padroniza confronto simples com vs');
assertTrue(($ticket['legs'][0]['market_key'] ?? '') === 'corners_total', 'detecta mercado de escanteios');
assertTrue(($ticket['legs'][0]['side'] ?? '') === 'over', 'detecta over');
assertTrue(abs((float)($ticket['legs'][0]['line'] ?? 0) - 8.5) < 0.0001, 'detecta linha 8.5');
assertTrue(($ticket['legs'][0]['event_date'] ?? '') === '2026-09-27', 'normaliza data');
assertTrue(abs((float)($ticket['total_odds'] ?? 0) - 1.60) < 0.0001, 'preserva odd');

$unicodeDashTicket = TicketNormalizer::fromModel([
    'kind'=>'simple',
    'odd'=>'1.50',
    'legs'=>[[
        'sport'=>'Futebol',
        'match'=>'Turquia – Itália',
        'market'=>'Resultado da partida',
        'selection'=>'Turquia',
        'odd'=>'1.50',
    ]],
]);
assertTrue(($unicodeDashTicket['legs'][0]['market_key'] ?? '') === 'match_result', 'normaliza resultado com travessao unicode');
assertTrue(($unicodeDashTicket['legs'][0]['side'] ?? '') === 'home', 'identifica mandante com travessao unicode');
assertTrue(($unicodeDashTicket['legs'][0]['match'] ?? '') === 'Turquia vs Itália', 'padroniza travessao unicode com vs');

foreach([
    'Time A x Time B',
    'Time A X Time B',
    'Time A × Time B',
    'Time A v Time B',
    'Time A vs Time B',
    'Time A - Time B',
    'Time A – Time B',
    'Time A — Time B',
    'Time A − Time B',
] as $legacyMatch){
    $normalized = TicketNormalizer::fromModel([
        'kind'=>'simple',
        'odd'=>'1.50',
        'legs'=>[[
            'sport'=>'Futebol',
            'match'=>$legacyMatch,
            'market'=>'Resultado da partida',
            'selection'=>'Time A',
            'odd'=>'1.50',
        ]],
    ]);
    assertTrue(($normalized['legs'][0]['match'] ?? '') === 'Time A vs Time B', 'regra global vs: '.$legacyMatch);
}

$needsStatistics = new ReflectionMethod(ResultWorker::class, 'needsStatistics');
$needsStatistics->setAccessible(true);
assertTrue($needsStatistics->invoke(null, [['market_key'=>'match_result']]) === false, 'resultado nao consome endpoint de estatisticas');
assertTrue($needsStatistics->invoke(null, [['market_key'=>'goals_total']]) === false, 'gols nao consomem endpoint de estatisticas');
assertTrue($needsStatistics->invoke(null, [['market_key'=>'corners_total']]) === true, 'escanteios consultam estatisticas somente na liquidacao');

$postMatchRetry = new ReflectionMethod(ResultWorker::class, 'postMatchRetryMinutes');
$postMatchRetry->setAccessible(true);
assertTrue($postMatchRetry->invoke(null, '2H', 80.0) === 25, '2H agenda unica consulta 15 min apos fim projetado');
assertTrue($postMatchRetry->invoke(null, 'HT', null) === 60, 'intervalo agenda somente consulta pos-jogo');
assertTrue($postMatchRetry->invoke(null, 'ET', 110.0) === 25, 'prorrogacao agenda consulta pos-fim projetado');

$fixtureMatcherSource=(string)file_get_contents(__DIR__.'/../app/Reporting/FixtureMatcher.php');
assertTrue(str_contains($fixtureMatcherSource,'foreach ($this->fixtures($date) as $fixture)'), 'matcher reutiliza consulta unica de fixtures por data');
assertTrue(!str_contains($fixtureMatcherSource,'$this->api->fixturesByTeamDate($teamA, $date)'), 'matcher nao usa mais team+date que pode exigir season');
$dateLookupPosition=strpos($fixtureMatcherSource,'foreach ($this->fixtures($date) as $fixture)');
$teamLookupPosition=strpos($fixtureMatcherSource,'$teamA = $this->safeResolveTeam($wantedA);');
assertTrue($dateLookupPosition!==false && $teamLookupPosition!==false && $dateLookupPosition<$teamLookupPosition, 'matcher consulta calendario antes de /teams?search');
assertTrue(str_contains($fixtureMatcherSource,'private array $dateFailureMessages = [];'), 'matcher evita repetir falha da mesma data dentro do ciclo');

$reportingRepositorySource=(string)file_get_contents(__DIR__.'/../app/Reporting/Repository.php');
assertTrue(str_contains($reportingRepositorySource, '$candidate = $now->setTime(23, 59, 0);'), 'sweep noturno fica em 23:59 America/Sao_Paulo');
assertTrue(!str_contains($reportingRepositorySource, '$candidate = $now->setTime(0, 15, 0);'), 'sweep antigo de 00:15 foi removido');

$resultWorkerSource=(string)file_get_contents(__DIR__.'/../app/Reporting/ResultWorker.php');
assertTrue(str_contains($resultWorkerSource,'if ($attempts >= 3)'), 'lookup permite dois retries controlados antes de revisao');
assertTrue(str_contains($resultWorkerSource,'Repository::rescheduleFixture($fixtureId, 30);'), 'fixture vazio ou interrompido recebe retry curto');
assertTrue(str_contains($resultWorkerSource,'Repository::rescheduleFixture($fixtureId, 60, $storedProvider);'), 'erro transitorio de fixture recebe retry em uma hora');
assertTrue(str_contains($resultWorkerSource,'TMR_REPORTING_SETTLEMENT_FALLBACK'), 'worker registra uso do fallback de resultados');
assertTrue(str_contains($resultWorkerSource,'TMR_REPORTING_FALLBACK_STATS_UNAVAILABLE'), 'fallback gratuito nao inventa estatisticas ausentes');

$apiFootballSource=(string)file_get_contents(__DIR__.'/../app/Reporting/ApiFootballClient.php');
assertTrue(str_contains($apiFootballSource,'private static function safeErrors'), 'cliente registra detalhes sanitizados de erro da API');
assertTrue(str_contains($apiFootballSource,"'errors' => $errors"), 'log da API preserva motivo sem expor credencial');

$matchSides = new ReflectionMethod(\App\Reporting\FixtureMatcher::class, 'matchSides');
$fixtureSides = $matchSides->invoke(null, 'Turquia – Itália');
assertTrue(($fixtureSides[0] ?? '') === 'Turquia' && ($fixtureSides[1] ?? '') === 'Itália', 'fixture matcher aceita travessao unicode');

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
