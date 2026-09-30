<?php declare(strict_types=1);

namespace App\Reporting;

require_once dirname(__DIR__).'/MatchNameFormatter.php';
use App\MatchNameFormatter;

final class FixtureMatcher
{
    private array $dateCache = [];
    private array $dateFailureMessages = [];
    private array $teamDateCache = [];
    private array $teamResolveCache = [];

    public function __construct(private readonly FootballResultsClient $api) {}

    public function match(array $leg): ?array
    {
        $detailed = $this->matchDetailed($leg);
        return ($detailed['status'] ?? '') === 'matched' ? ($detailed['match'] ?? null) : null;
    }

    public function matchDetailed(array $leg): array
    {
        [$wantedA, $wantedB] = self::matchSides((string)($leg['match_name'] ?? ''));
        if ($wantedA === null || $wantedB === null) {
            return ['status'=>'invalid_match_name','match'=>null];
        }

        $dates = self::candidateDates(
            isset($leg['event_date']) ? (string)$leg['event_date'] : null,
            isset($leg['placed_at']) ? (string)$leg['placed_at'] : null
        );

        // Primeira escolha: uma consulta de fixtures por data, reutilizada para
        // todas as apostas do mesmo dia. Isso evita chamadas /teams?search
        // quando os nomes oficiais já permitem um match confiável.
        $candidates = [];
        foreach ($dates as $date) {
            foreach ($this->fixtures($date) as $fixture) {
                if (!is_array($fixture)) {
                    continue;
                }
                $home = trim((string)($fixture['teams']['home']['name'] ?? ''));
                $away = trim((string)($fixture['teams']['away']['name'] ?? ''));
                $fixtureId = (int)($fixture['fixture']['id'] ?? 0);
                if ($home === '' || $away === '' || $fixtureId <= 0) {
                    continue;
                }

                $ordered = (self::similarity($wantedA, $home) + self::similarity($wantedB, $away)) / 2;
                $swapped = (self::similarity($wantedA, $away) + self::similarity($wantedB, $home)) / 2;
                $score = max($ordered, $swapped);

                $leagueWanted = trim((string)($leg['league'] ?? ''));
                $leagueActual = trim((string)($fixture['league']['name'] ?? ''));
                if ($leagueWanted !== '' && $leagueActual !== '') {
                    $leagueScore = self::similarity($leagueWanted, $leagueActual);
                    $score = ($score * 0.97) + ($leagueScore * 0.03);
                }

                $candidates[] = [
                    'fixture_id' => $fixtureId,
                    'home_team' => $home,
                    'away_team' => $away,
                    'kickoff_at' => self::utcDate((string)($fixture['fixture']['date'] ?? '')),
                    'confidence' => round($score, 4),
                    'match_method' => 'text_date_first',
                ];
            }
        }

        if ($candidates !== []) {
            usort($candidates, static fn(array $a, array $b): int => $b['confidence'] <=> $a['confidence']);
            $best = $candidates[0];
            if ((float)$best['confidence'] >= 0.94) {
                return ['status'=>'matched','match'=>$best];
            }
        }

        $teamA = null;
        $teamB = null;

        // IDs de equipes não são portáveis entre provedores. A resolução por
        // aliases/IDs permanece exclusiva da API-Football; o fallback usa o
        // calendário por data + similaridade textual.
        if ($this->api->providerKey() === 'api_football') {
            $teamA = $this->safeResolveTeam($wantedA);
            $teamB = $this->safeResolveTeam($wantedB);
            if (is_array($teamA) && is_array($teamB) && (int)$teamA['team_id'] !== (int)$teamB['team_id']) {
                foreach ($dates as $date) {
                    $exact = $this->matchByTeamIds(
                        (int)$teamA['team_id'],
                        (int)$teamB['team_id'],
                        $date
                    );
                    if ($exact !== null) {
                        $exact['confidence'] = 1.0;
                        $exact['match_method'] = 'team_ids';
                        return ['status'=>'matched','match'=>$exact];
                    }
                }
            }
        }

        if ($candidates === []) {
            return [
                'status'=>'not_found',
                'match'=>null,
                'team_a_resolved'=>is_array($teamA),
                'team_b_resolved'=>is_array($teamB),
            ];
        }

        $best = $candidates[0];
        $second = $candidates[1]['confidence'] ?? 0.0;

        if ($best['confidence'] < 0.78) {
            return [
                'status'=>'low_confidence',
                'match'=>null,
                'best_confidence'=>$best['confidence'],
                'team_a_resolved'=>is_array($teamA),
                'team_b_resolved'=>is_array($teamB),
            ];
        }
        if ($best['confidence'] < 0.94 && ($best['confidence'] - $second) < 0.035) {
            return [
                'status'=>'ambiguous',
                'match'=>null,
                'best_confidence'=>$best['confidence'],
                'second_confidence'=>$second,
                'team_a_resolved'=>is_array($teamA),
                'team_b_resolved'=>is_array($teamB),
            ];
        }

        return ['status'=>'matched','match'=>$best];
    }

    private function safeResolveTeam(string $input): ?array
    {
        try {
            return $this->resolveTeam($input);
        } catch (\Throwable $e) {
            $cacheKey = self::key($input);
            if ($cacheKey !== '') {
                $this->teamResolveCache[$cacheKey] = null;
            }
            error_log('TMR_REPORTING_TEAM_RESOLVE_NON_FATAL ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 120));
            return null;
        }
    }

    private function resolveTeam(string $input): ?array
    {
        $cacheKey = self::key($input);
        if ($cacheKey === '') {
            return null;
        }
        if (array_key_exists($cacheKey, $this->teamResolveCache)) {
            return $this->teamResolveCache[$cacheKey];
        }

        $stored = Repository::findTeamAlias($input);
        if (is_array($stored)) {
            return $this->teamResolveCache[$cacheKey] = [
                'team_id'=>(int)$stored['team_id'],
                'api_name'=>(string)$stored['api_name'],
                'country'=>(string)($stored['country'] ?? ''),
                'confidence'=>(float)($stored['confidence'] ?? 1),
            ];
        }

        $canonical = self::canonicalAlias($input);
        $queries = array_values(array_unique(
            self::key($canonical) !== self::key($input)
                ? [$canonical, trim($input)]
                : [trim($input)]
        ));

        $best = null;
        foreach ($queries as $query) {
            if ($query === '') {
                continue;
            }
            foreach ($this->api->teamsSearch($query) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $team = is_array($row['team'] ?? null) ? $row['team'] : [];
                $teamId = (int)($team['id'] ?? 0);
                $apiName = trim((string)($team['name'] ?? ''));
                if ($teamId <= 0 || $apiName === '') {
                    continue;
                }

                $score = max(
                    self::similarity($input, $apiName),
                    self::similarity(self::canonicalAlias($input), $apiName),
                    self::similarity($query, $apiName)
                );

                // National teams are common in translated names such as Turquia/Itália.
                $national = !empty($team['national']);
                if ($national && self::looksLikeCountryName($input)) {
                    $score = min(1.0, $score + 0.08);
                }

                if ($best === null || $score > $best['confidence']) {
                    $best = [
                        'team_id'=>$teamId,
                        'api_name'=>$apiName,
                        'country'=>trim((string)($team['country'] ?? '')),
                        'confidence'=>round($score, 4),
                    ];
                }
            }
            if (is_array($best) && $best['confidence'] >= 0.94) {
                break;
            }
        }

        if (!is_array($best) || $best['confidence'] < 0.72) {
            return $this->teamResolveCache[$cacheKey] = null;
        }

        Repository::saveTeamAlias(
            $input,
            (int)$best['team_id'],
            (string)$best['api_name'],
            (string)$best['country'],
            (float)$best['confidence']
        );
        Repository::saveTeamAlias(
            (string)$best['api_name'],
            (int)$best['team_id'],
            (string)$best['api_name'],
            (string)$best['country'],
            1.0
        );

        if ($canonical !== '' && self::key($canonical) !== self::key($input)) {
            Repository::saveTeamAlias(
                $canonical,
                (int)$best['team_id'],
                (string)$best['api_name'],
                (string)$best['country'],
                (float)$best['confidence']
            );
        }

        return $this->teamResolveCache[$cacheKey] = $best;
    }

    private function matchByTeamIds(int $teamA, int $teamB, string $date): ?array
    {
        // Use the date-wide fixture cache and filter by stable team IDs locally.
        // This avoids API-Football validation of team+date combinations that can
        // require a season, and one daily request can serve every pending ticket.
        foreach ($this->fixtures($date) as $fixture) {
            if (!is_array($fixture)) {
                continue;
            }

            $homeId = (int)($fixture['teams']['home']['id'] ?? 0);
            $awayId = (int)($fixture['teams']['away']['id'] ?? 0);
            if (!(($homeId === $teamA && $awayId === $teamB) || ($homeId === $teamB && $awayId === $teamA))) {
                continue;
            }

            $fixtureId = (int)($fixture['fixture']['id'] ?? 0);
            if ($fixtureId <= 0) {
                continue;
            }

            return [
                'fixture_id'=>$fixtureId,
                'home_team'=>trim((string)($fixture['teams']['home']['name'] ?? '')),
                'away_team'=>trim((string)($fixture['teams']['away']['name'] ?? '')),
                'kickoff_at'=>self::utcDate((string)($fixture['fixture']['date'] ?? '')),
            ];
        }

        return null;
    }

    private function fixtures(string $date): array
    {
        if (isset($this->dateFailureMessages[$date])) {
            throw new \RuntimeException($this->dateFailureMessages[$date]);
        }
        if (!array_key_exists($date, $this->dateCache)) {
            try {
                $this->dateCache[$date] = $this->api->fixturesByDate($date);
            } catch (\Throwable $e) {
                $message = trim($e->getMessage()) !== '' ? $e->getMessage() : 'Falha na consulta de fixtures por data.';
                $this->dateFailureMessages[$date] = $message;
                throw $e;
            }
        }
        return $this->dateCache[$date];
    }

    private static function candidateDates(?string $eventDate, ?string $placedAtUtc): array
    {
        $tz = new \DateTimeZone('America/Sao_Paulo');
        if ($eventDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $eventDate)) {
            return [$eventDate];
        }

        try {
            $base = $placedAtUtc !== null && $placedAtUtc !== ''
                ? (new \DateTimeImmutable($placedAtUtc, new \DateTimeZone('UTC')))->setTimezone($tz)
                : new \DateTimeImmutable('now', $tz);
        } catch (\Throwable) {
            $base = new \DateTimeImmutable('now', $tz);
        }

        return [$base->format('Y-m-d')];
    }

    private static function matchSides(string $match): array
    {
        return MatchNameFormatter::split($match);
    }

    private static function similarity(string $a, string $b): float
    {
        $a = self::key($a);
        $b = self::key($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        similar_text($a, $b, $percent);
        return $percent / 100;
    }

    private static function key(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($ascii) ? strtolower($ascii) : $value;
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    private static function canonicalAlias(string $value): string
    {
        $key = self::key($value);
        $map = [
            'turquia'=>'Turkey',
            'turkiye'=>'Turkey',
            'italia'=>'Italy',
            'alemanha'=>'Germany',
            'espanha'=>'Spain',
            'franca'=>'France',
            'inglaterra'=>'England',
            'holanda'=>'Netherlands',
            'paisesbaixos'=>'Netherlands',
            'belgica'=>'Belgium',
            'suica'=>'Switzerland',
            'austria'=>'Austria',
            'croacia'=>'Croatia',
            'grecia'=>'Greece',
            'polonia'=>'Poland',
            'hungria'=>'Hungary',
            'romenia'=>'Romania',
            'servia'=>'Serbia',
            'ucrania'=>'Ukraine',
            'tchequia'=>'Czechia',
            'republicatcheca'=>'Czechia',
            'portugal'=>'Portugal',
            'brasil'=>'Brazil',
            'argentina'=>'Argentina',
            'uruguai'=>'Uruguay',
            'paraguai'=>'Paraguay',
            'colombia'=>'Colombia',
            'equador'=>'Ecuador',
            'japao'=>'Japan',
            'coreiadosul'=>'South Korea',
            'estadosunidos'=>'USA',
            'eua'=>'USA',
            'arabiasaudita'=>'Saudi Arabia',
        ];
        return $map[$key] ?? trim($value);
    }

    private static function looksLikeCountryName(string $value): bool
    {
        $canonical = self::canonicalAlias($value);
        return self::key($canonical) !== '' && (
            self::key($canonical) !== self::key($value)
            || in_array(self::key($canonical), [
                'portugal','brazil','argentina','uruguay','paraguay','colombia',
                'ecuador','japan','usa','england','france','spain','germany','italy',
                'turkey','netherlands','belgium','switzerland','austria','croatia',
                'greece','poland','hungary','romania','serbia','ukraine','czechia',
                'southkorea','saudiarabia'
            ], true)
        );
    }

    private static function utcDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
