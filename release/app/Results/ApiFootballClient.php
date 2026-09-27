<?php declare(strict_types=1);

namespace App\Results;

final class ApiFootballClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://v3.football.api-sports.io'
    ) {}

    public function fixtureStatistics(int $fixtureId): array
    {
        if ($fixtureId <= 0) throw new \InvalidArgumentException('fixtureId inválido.');
        $payload = $this->get('/fixtures/statistics?fixture=' . $fixtureId);
        $teams = $payload['response'] ?? [];
        if (!is_array($teams) || count($teams) < 2) return [];

        $totals = ['corners_total'=>0.0,'fouls_total'=>0.0,'yellow_cards_total'=>0.0,'red_cards_total'=>0.0];
        $found = [];
        foreach ($teams as $team) {
            foreach (($team['statistics'] ?? []) as $stat) {
                $type = strtolower(trim((string)($stat['type'] ?? '')));
                $value = $stat['value'] ?? null;
                if (!is_numeric($value)) continue;
                $map = ['corner kicks'=>'corners_total','fouls'=>'fouls_total','yellow cards'=>'yellow_cards_total','red cards'=>'red_cards_total'];
                if (!isset($map[$type])) continue;
                $key=$map[$type]; $totals[$key]+=(float)$value; $found[$key]=true;
            }
        }
        foreach (array_keys($totals) as $key) if (!isset($found[$key])) unset($totals[$key]);
        if (isset($totals['yellow_cards_total']) || isset($totals['red_cards_total'])) {
            $totals['cards_total']=($totals['yellow_cards_total']??0)+($totals['red_cards_total']??0);
        }
        return $totals;
    }

    public function fixturesByDate(string $date): array
    {
        $payload=$this->get('/fixtures?date='.rawurlencode($date));
        return is_array($payload['response']??null)?$payload['response']:[];
    }

    public function fixture(int $fixtureId): array
    {
        $payload=$this->get('/fixtures?id='.$fixtureId);
        return is_array($payload['response'][0]??null)?$payload['response'][0]:[];
    }

    private function get(string $path): array
    {
        if ($this->apiKey === '') throw new \RuntimeException('API_FOOTBALL_KEY não configurada.');
        $ch=curl_init(rtrim($this->baseUrl,'/').$path);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>['x-apisports-key: '.$this->apiKey,'Accept: application/json']]);
        $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch);
        if ($body===false || $error!=='') throw new \RuntimeException('Falha na API de resultados: '.$error);
        if ($code<200 || $code>=300) throw new \RuntimeException('API de resultados respondeu HTTP '.$code.'.');
        $data=json_decode($body,true);
        if (!is_array($data)) throw new \RuntimeException('Resposta inválida da API de resultados.');
        return $data;
    }
}
