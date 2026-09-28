<?php declare(strict_types=1);

namespace App\Reporting;

final class FixtureMatcher
{
    private array $dateCache = [];

    public function __construct(private readonly ApiFootballClient $api) {}

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
                    $score = ($score * 0.9) + ($leagueScore * 0.1);
                }

                $candidates[] = [
                    'fixture_id' => $fixtureId,
                    'home_team' => $home,
                    'away_team' => $away,
                    'kickoff_at' => self::utcDate((string)($fixture['fixture']['date'] ?? '')),
                    'confidence' => round($score, 4),
                ];
            }
        }

        if ($candidates === []) {
            return ['status'=>'not_found','match'=>null];
        }

        usort($candidates, static fn(array $a, array $b): int => $b['confidence'] <=> $a['confidence']);
        $best = $candidates[0];
        $second = $candidates[1]['confidence'] ?? 0.0;

        if ($best['confidence'] < 0.82) {
            return [
                'status'=>'low_confidence',
                'match'=>null,
                'best_confidence'=>$best['confidence'],
            ];
        }
        if ($best['confidence'] < 0.96 && ($best['confidence'] - $second) < 0.04) {
            return [
                'status'=>'ambiguous',
                'match'=>null,
                'best_confidence'=>$best['confidence'],
                'second_confidence'=>$second,
            ];
        }

        return ['status'=>'matched','match'=>$best];
    }

    private function fixtures(string $date): array
    {
        if (!array_key_exists($date, $this->dateCache)) {
            $this->dateCache[$date] = $this->api->fixturesByDate($date);
        }
        return $this->dateCache[$date];
    }

    private static function candidateDates(?string $eventDate, ?string $placedAtUtc): array
    {
        $tz = new \DateTimeZone('America/Sao_Paulo');

        // Uma única consulta inicial: usa a data oficial extraída da tip/card.
        // Se ela não existir, usa a data local em que a aposta foi registrada.
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
        foreach ([
            '/\s+[x×]\s+/iu',
            '/\s+vs\.?\s+/iu',
            '/\s+v\s+/iu',
            '/\s+-\s+/u',
        ] as $pattern) {
            $parts = preg_split($pattern, trim($match), 2);
            if (is_array($parts) && count($parts) === 2) {
                $a = trim((string)$parts[0]);
                $b = trim((string)$parts[1]);
                if ($a !== '' && $b !== '') {
                    return [$a, $b];
                }
            }
        }
        return [null, null];
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

    private static function utcDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
