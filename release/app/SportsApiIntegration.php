<?php declare(strict_types=1);

namespace App;

/**
 * Centraliza credenciais e testes das APIs esportivas exibidas no painel.
 * A chave salva no banco tem prioridade; variáveis de ambiente permanecem
 * como fallback operacional para instalações existentes.
 */
final class SportsApiIntegration
{
    private const STAKE_BASE_URL = 'https://odds-data.stake.com';
    private const API_FOOTBALL_BASE_URL = 'https://v3.football.api-sports.io';

    public static function stakeKey(): string
    {
        return self::storedOrEnv('stake_odds_api_key', 'STAKE_ODDS_API_KEY');
    }

    public static function apiFootballKey(): string
    {
        return self::storedOrEnv('api_football_key', 'API_FOOTBALL_KEY');
    }

    /** @return array{ok:bool,message:string,latency_ms:int,http_code:?int,error:?string} */
    public static function test(string $provider, string $overrideKey = ''): array
    {
        $provider = trim($provider);
        $key = trim($overrideKey);

        if ($provider === 'stake') {
            if ($key === '') $key = self::stakeKey();
            return self::testStake($key);
        }

        if ($provider === 'api_football') {
            if ($key === '') $key = self::apiFootballKey();
            return self::testApiFootball($key);
        }

        return [
            'ok' => false,
            'message' => 'Provedor esportivo inválido.',
            'latency_ms' => 0,
            'http_code' => null,
            'error' => 'Provedor esportivo inválido.',
        ];
    }

    private static function storedOrEnv(string $settingKey, string $envKey): string
    {
        try {
            if (class_exists(Repository::class)) {
                $stored = trim(Repository::integration($settingKey));
                if ($stored !== '') return $stored;
            }
        } catch (\Throwable) {
            // Mantém compatibilidade com workers/testes que ainda dependem do ambiente.
        }

        return trim((string)(getenv($envKey) ?: ''));
    }

    /** @return array{ok:bool,message:string,latency_ms:int,http_code:?int,error:?string} */
    private static function testStake(string $key): array
    {
        if ($key === '') return self::missing('Informe uma chave da Stake para testar.');

        $modes = [
            ['X-API-Key: ' . $key],
            ['apiKey: ' . $key],
            ['Authorization: Bearer ' . $key],
        ];

        $last = null;
        foreach ($modes as $headers) {
            $result = self::request(
                self::STAKE_BASE_URL . '/sport/soccer/fixture',
                array_merge(['Accept: application/json'], $headers)
            );
            $last = $result;

            if ($result['ok']) {
                return [
                    'ok' => true,
                    'message' => 'Stake conectada com sucesso.',
                    'latency_ms' => $result['latency_ms'],
                    'http_code' => $result['http_code'],
                    'error' => null,
                ];
            }

            if (!in_array((int)($result['http_code'] ?? 0), [401, 403], true)) break;
        }

        return self::failed('Stake', $last);
    }

    /** @return array{ok:bool,message:string,latency_ms:int,http_code:?int,error:?string} */
    private static function testApiFootball(string $key): array
    {
        if ($key === '') return self::missing('Informe uma chave da API-Football para testar.');

        $result = self::request(
            self::API_FOOTBALL_BASE_URL . '/status',
            [
                'Accept: application/json',
                'x-apisports-key: ' . $key,
            ]
        );

        if ($result['ok']) {
            return [
                'ok' => true,
                'message' => 'API-Football conectada com sucesso.',
                'latency_ms' => $result['latency_ms'],
                'http_code' => $result['http_code'],
                'error' => null,
            ];
        }

        return self::failed('API-Football', $result);
    }

    /** @return array{ok:bool,http_code:?int,latency_ms:int,error:?string} */
    private static function request(string $url, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return ['ok'=>false,'http_code'=>null,'latency_ms'=>0,'error'=>'Extensão cURL indisponível.'];
        }

        $started = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok'=>false,'http_code'=>null,'latency_ms'=>0,'error'=>'Falha ao iniciar cliente HTTP.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT_MS => 2500,
            CURLOPT_TIMEOUT_MS => 7000,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $latency = max(0, (int)round((microtime(true) - $started) * 1000));

        if ($body === false || $errno !== 0) {
            return ['ok'=>false,'http_code'=>$http ?: null,'latency_ms'=>$latency,'error'=>'Falha de rede ao consultar o provedor.'];
        }

        $decoded = json_decode((string)$body, true);
        if ($http >= 200 && $http < 300 && is_array($decoded)) {
            if (isset($decoded['errors']) && !empty($decoded['errors'])) {
                return ['ok'=>false,'http_code'=>$http,'latency_ms'=>$latency,'error'=>'O provedor rejeitou a solicitação.'];
            }
            return ['ok'=>true,'http_code'=>$http,'latency_ms'=>$latency,'error'=>null];
        }

        $error = match ($http) {
            401, 403 => 'Chave rejeitada pelo provedor.',
            429 => 'Limite de requisições atingido.',
            default => $http > 0 ? 'Resposta HTTP ' . $http . '.' : 'Sem resposta HTTP válida.',
        };

        return ['ok'=>false,'http_code'=>$http ?: null,'latency_ms'=>$latency,'error'=>$error];
    }

    /** @return array{ok:bool,message:string,latency_ms:int,http_code:?int,error:?string} */
    private static function missing(string $message): array
    {
        return ['ok'=>false,'message'=>$message,'latency_ms'=>0,'http_code'=>null,'error'=>$message];
    }

    /** @param array{ok:bool,http_code:?int,latency_ms:int,error:?string}|null $result */
    private static function failed(string $label, ?array $result): array
    {
        $error = trim((string)($result['error'] ?? 'Falha inesperada no teste.'));
        return [
            'ok' => false,
            'message' => $label . ': ' . $error,
            'latency_ms' => (int)($result['latency_ms'] ?? 0),
            'http_code' => isset($result['http_code']) ? (int)$result['http_code'] : null,
            'error' => $error,
        ];
    }
}
