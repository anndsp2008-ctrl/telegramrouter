<?php declare(strict_types=1);

namespace App\Reporting;

final class ApiFootballClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://v3.football.api-sports.io'
    ) {}

    public function fixturesByDate(string $date): array
    {
        $payload = $this->get('/fixtures?date=' . rawurlencode($date));
        return is_array($payload['response'] ?? null) ? $payload['response'] : [];
    }

    public function teamsSearch(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }
        $payload = $this->get('/teams?search=' . rawurlencode($search));
        return is_array($payload['response'] ?? null) ? $payload['response'] : [];
    }

    public function fixturesByTeamDate(int $teamId, string $date): array
    {
        if ($teamId <= 0) {
            throw new \InvalidArgumentException('teamId inválido.');
        }
        $payload = $this->get(
            '/fixtures?team=' . $teamId . '&date=' . rawurlencode($date)
        );
        return is_array($payload['response'] ?? null) ? $payload['response'] : [];
    }

    public function fixture(int $fixtureId): array
    {
        if ($fixtureId <= 0) {
            throw new \InvalidArgumentException('fixtureId inválido.');
        }
        $payload = $this->get('/fixtures?id=' . $fixtureId);
        return is_array($payload['response'][0] ?? null) ? $payload['response'][0] : [];
    }

    public function statistics(int $fixtureId): array
    {
        if ($fixtureId <= 0) {
            throw new \InvalidArgumentException('fixtureId inválido.');
        }
        $payload = $this->get('/fixtures/statistics?fixture=' . $fixtureId);
        $rows = is_array($payload['response'] ?? null) ? $payload['response'] : [];

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $teamId = (int)($row['team']['id'] ?? 0);
            if ($teamId <= 0) {
                continue;
            }

            $stats = [
                'team_name' => trim((string)($row['team']['name'] ?? '')),
                'corners' => null,
                'fouls' => null,
                'yellow_cards' => null,
                'red_cards' => null,
            ];

            foreach (($row['statistics'] ?? []) as $stat) {
                if (!is_array($stat)) {
                    continue;
                }
                $type = strtolower(trim((string)($stat['type'] ?? '')));
                $value = $stat['value'] ?? null;
                if (!is_numeric($value)) {
                    continue;
                }

                match ($type) {
                    'corner kicks' => $stats['corners'] = (float)$value,
                    'fouls' => $stats['fouls'] = (float)$value,
                    'yellow cards' => $stats['yellow_cards'] = (float)$value,
                    'red cards' => $stats['red_cards'] = (float)$value,
                    default => null,
                };
            }

            $result[$teamId] = $stats;
        }

        return $result;
    }

    private function get(string $path): array
    {
        if (trim($this->apiKey) === '') {
            throw new \RuntimeException('API_FOOTBALL_KEY não configurada.');
        }

        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);
        if ($ch === false) {
            throw new \RuntimeException('Falha ao iniciar cliente HTTP.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'x-apisports-key: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new \RuntimeException('Falha na API de resultados.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('API de resultados respondeu HTTP ' . $status . '.');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Resposta inválida da API de resultados.');
        }

        if (!empty($data['errors'])) {
            $errors = self::safeErrors($data['errors']);
            error_log('TMR_API_FOOTBALL_APPLICATION_ERROR ' . json_encode([
                'errors' => $errors,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $primary = array_key_first($errors);
            throw new \RuntimeException(
                'API de resultados retornou erro de aplicação [' . ($primary !== null ? (string)$primary : 'unknown') . '].'
            );
        }

        return $data;
    }

    private static function safeErrors(mixed $errors): array
    {
        if (!is_array($errors)) {
            return ['unknown'=>self::safeErrorText($errors)];
        }

        $safe = [];
        foreach ($errors as $key => $value) {
            $safe[(string)$key] = self::safeErrorText($value);
            if (count($safe) >= 8) {
                break;
            }
        }
        return $safe !== [] ? $safe : ['unknown'=>'Erro não detalhado pelo provedor.'];
    }

    private static function safeErrorText(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            $text = trim((string)$value);
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $text = is_string($encoded) ? $encoded : 'Erro não serializável.';
        }

        $text = preg_replace(
            '/\b(api[-_ ]?key|token|secret)\s*[:=]\s*[^\s,;]+/iu',
            '$1=[redacted]',
            $text
        ) ?? $text;
        $text = preg_replace('/\b[A-Za-z0-9_-]{32,}\b/', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_substr($text !== '' ? $text : 'Erro não detalhado pelo provedor.', 0, 180);
    }
}
