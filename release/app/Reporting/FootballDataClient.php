<?php declare(strict_types=1);

namespace App\Reporting;

final class FootballDataClient implements FootballResultsClient
{
    private array $dateCache = [];
    private array $fixtureCache = [];
    private int $requests = 0;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $baseUrl = 'https://api.football-data.org',
        private readonly int $requestBudget = 8
    ) {}

    public function providerKey(): string
    {
        return 'football_data';
    }

    public function fixturesByDate(string $date): array
    {
        if (array_key_exists($date, $this->dateCache)) {
            return $this->dateCache[$date];
        }

        $payload = $this->get(
            '/v4/matches?dateFrom=' . rawurlencode($date) . '&dateTo=' . rawurlencode($date)
        );
        $rows = is_array($payload['matches'] ?? null) ? $payload['matches'] : [];

        $mapped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $fixture = self::normalizeMatch($row);
            $id = (int)($fixture['fixture']['id'] ?? 0);
            if ($id <= 0) continue;
            $mapped[] = $fixture;
            $this->fixtureCache[$id] = $fixture;
        }

        return $this->dateCache[$date] = $mapped;
    }

    public function teamsSearch(string $search): array
    {
        // A API v4 não documenta busca textual equivalente a /teams?search.
        // O FixtureMatcher continua usando a comparação por calendário/data,
        // que é a primeira estratégia e não depende deste método.
        return [];
    }

    public function fixturesByTeamDate(int $teamId, string $date): array
    {
        if ($teamId <= 0) {
            throw new \InvalidArgumentException('teamId inválido.');
        }

        return array_values(array_filter(
            $this->fixturesByDate($date),
            static fn(array $fixture): bool =>
                (int)($fixture['teams']['home']['id'] ?? 0) === $teamId
                || (int)($fixture['teams']['away']['id'] ?? 0) === $teamId
        ));
    }

    public function fixture(int $fixtureId): array
    {
        if ($fixtureId <= 0) {
            throw new \InvalidArgumentException('fixtureId inválido.');
        }
        if (isset($this->fixtureCache[$fixtureId])) {
            return $this->fixtureCache[$fixtureId];
        }

        $payload = $this->get('/v4/matches/' . $fixtureId);
        if (!isset($payload['id'])) {
            return [];
        }

        $fixture = self::normalizeMatch($payload);
        if ((int)($fixture['fixture']['id'] ?? 0) > 0) {
            $this->fixtureCache[$fixtureId] = $fixture;
        }
        return $fixture;
    }

    public function statistics(int $fixtureId): array
    {
        // O fallback foi desenhado para o plano gratuito. Estatísticas como
        // escanteios/faltas/cartões dependem do Statistic Add-On e, portanto,
        // jamais são simuladas ou convertidas para zero.
        return [];
    }

    public function requestsUsed(): int
    {
        return $this->requests;
    }

    private function get(string $path): array
    {
        if (trim($this->apiToken) === '') {
            throw new \RuntimeException('FOOTBALL_DATA_TOKEN não configurado.');
        }
        if ($this->requests >= max(1, $this->requestBudget)) {
            throw new \RuntimeException('Limite interno do fallback football-data.org atingido neste ciclo.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Extensão cURL indisponível.');
        }

        $this->requests++;
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Falha ao iniciar cliente football-data.org.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'X-Auth-Token: ' . $this->apiToken,
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new \RuntimeException('Falha de rede no fallback football-data.org.');
        }
        if ($status === 429) {
            throw new \RuntimeException('football-data.org atingiu o limite de requisições.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('football-data.org respondeu HTTP ' . $status . '.');
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Resposta inválida do fallback football-data.org.');
        }

        return $data;
    }

    private static function normalizeMatch(array $match): array
    {
        $home = is_array($match['homeTeam'] ?? null) ? $match['homeTeam'] : [];
        $away = is_array($match['awayTeam'] ?? null) ? $match['awayTeam'] : [];
        $competition = is_array($match['competition'] ?? null) ? $match['competition'] : [];
        $score = is_array($match['score'] ?? null) ? $match['score'] : [];
        $fullTime = is_array($score['fullTime'] ?? null) ? $score['fullTime'] : [];
        $regularTime = is_array($score['regularTime'] ?? null) ? $score['regularTime'] : [];
        $extraTime = is_array($score['extraTime'] ?? null) ? $score['extraTime'] : [];
        $penalties = is_array($score['penalties'] ?? null) ? $score['penalties'] : [];
        $duration = strtoupper(trim((string)($score['duration'] ?? 'REGULAR')));

        [$homeScore, $awayScore] = self::regulationScore(
            $duration,
            $fullTime,
            $regularTime,
            $extraTime,
            $penalties
        );

        return [
            'fixture' => [
                'id' => (int)($match['id'] ?? 0),
                'date' => trim((string)($match['utcDate'] ?? '')),
                'status' => [
                    'short' => self::statusShort((string)($match['status'] ?? ''), $duration),
                    'long' => trim((string)($match['status'] ?? '')),
                    'elapsed' => null,
                ],
            ],
            'league' => [
                'id' => (int)($competition['id'] ?? 0),
                'name' => trim((string)($competition['name'] ?? '')),
            ],
            'teams' => [
                'home' => [
                    'id' => (int)($home['id'] ?? 0),
                    'name' => trim((string)($home['name'] ?? $home['shortName'] ?? '')),
                ],
                'away' => [
                    'id' => (int)($away['id'] ?? 0),
                    'name' => trim((string)($away['name'] ?? $away['shortName'] ?? '')),
                ],
            ],
            'goals' => [
                'home' => $homeScore,
                'away' => $awayScore,
            ],
            'score' => [
                'fulltime' => [
                    'home' => $homeScore,
                    'away' => $awayScore,
                ],
            ],
            '_provider' => 'football_data',
        ];
    }

    private static function statusShort(string $status, string $duration = ''): string
    {
        $status = strtoupper(trim($status));
        $duration = strtoupper(trim($duration));

        if ($status === 'FINISHED') {
            return match ($duration) {
                'PENALTY_SHOOTOUT' => 'PEN',
                'EXTRA_TIME' => 'AET',
                default => 'FT',
            };
        }

        return match ($status) {
            'IN_PLAY', 'LIVE' => '2H',
            'PAUSED' => 'HT',
            'EXTRA_TIME', 'PENALTY_SHOOTOUT' => 'ET',
            'POSTPONED' => 'PST',
            'SUSPENDED' => 'SUSP',
            'CANCELLED' => 'CANC',
            'AWARDED' => 'AWD',
            'SCHEDULED', 'TIMED' => 'NS',
            default => 'TBD',
        };
    }

    private static function regulationScore(
        string $duration,
        array $fullTime,
        array $regularTime,
        array $extraTime,
        array $penalties
    ): array {
        $regularHome = self::number($regularTime['home'] ?? null);
        $regularAway = self::number($regularTime['away'] ?? null);
        if ($regularHome !== null && $regularAway !== null) {
            return [$regularHome, $regularAway];
        }

        $home = self::number($fullTime['home'] ?? null);
        $away = self::number($fullTime['away'] ?? null);
        if ($home === null || $away === null) {
            return [$home, $away];
        }

        // football-data.org can expose a cumulative "fullTime" for knockout
        // matches. Convert it back to the 90-minute score expected by the
        // existing settlement engine.
        if ($duration === 'PENALTY_SHOOTOUT') {
            $home -= self::number($penalties['home'] ?? null) ?? 0.0;
            $away -= self::number($penalties['away'] ?? null) ?? 0.0;
        }
        if (in_array($duration, ['EXTRA_TIME','PENALTY_SHOOTOUT'], true)) {
            $home -= self::number($extraTime['home'] ?? null) ?? 0.0;
            $away -= self::number($extraTime['away'] ?? null) ?? 0.0;
        }

        return [max(0.0, $home), max(0.0, $away)];
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}
