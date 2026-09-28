<?php declare(strict_types=1);

namespace App\Reporting;

final class TicketNormalizer
{
    public static function fromModel(array $model): ?array
    {
        $kind = strtolower(trim((string)($model['kind'] ?? 'simple')));
        if (!in_array($kind, ['simple','double','multiple','bet_builder'], true)) {
            $kind = 'simple';
        }

        $rawLegs = is_array($model['legs'] ?? null) ? $model['legs'] : [];
        if ($rawLegs === []) {
            $match = trim((string)($model['match'] ?? ''));
            $market = trim((string)($model['market'] ?? ''));
            $selection = trim((string)($model['selection'] ?? ''));
            if ($match !== '' && $market !== '' && $selection !== '') {
                $rawLegs = [[
                    'sport' => (string)($model['sport'] ?? ''),
                    'match' => $match,
                    'league' => (string)($model['league'] ?? ''),
                    'date' => (string)($model['day'] ?? ''),
                    'market' => $market,
                    'selection' => $selection,
                    'odd' => (string)($model['odd'] ?? ''),
                ]];
            }
        }

        if ($rawLegs === [] || count($rawLegs) > 16) {
            return null;
        }

        $legs = [];
        foreach ($rawLegs as $index => $rawLeg) {
            if (!is_array($rawLeg)) {
                return null;
            }
            $leg = self::normalizeLeg($rawLeg, $index + 1);
            if ($leg === null) {
                return null;
            }
            $legs[] = $leg;
        }

        $totalOdds = self::decimal($model['odd'] ?? null);
        if ($totalOdds === null && count($legs) === 1) {
            $totalOdds = $legs[0]['odds'];
        }

        return [
            'kind' => $kind,
            'bookmaker' => mb_substr(trim((string)($model['bookmaker'] ?? $model['bookmaker_name'] ?? '')), 0, 120),
            'total_odds' => $totalOdds,
            'stake_units' => 10.0,
            'legs' => $legs,
        ];
    }

    private static function normalizeLeg(array $raw, int $position): ?array
    {
        $sport = trim((string)($raw['sport'] ?? ''));
        $match = trim((string)($raw['match'] ?? ''));
        $league = trim((string)($raw['league'] ?? ''));
        $market = trim((string)($raw['market'] ?? ''));
        $selection = trim((string)($raw['selection'] ?? ''));

        if ($match === '' || $market === '' || $selection === '') {
            return null;
        }

        [$left, $right] = self::matchSides($match);
        $haystack = self::key($market . ' ' . $selection);
        $teamMarket = preg_match('/\b(team|equipe|time|equipo)\b/u', $haystack) === 1;

        $marketKey = 'unsupported';
        if (preg_match('/\b(corner|corners|escanteio|escanteios|corneres)\b/u', $haystack)) {
            $marketKey = $teamMarket ? 'team_corners_total' : 'corners_total';
        } elseif (preg_match('/\b(foul|fouls|falta|faltas)\b/u', $haystack)) {
            $marketKey = $teamMarket ? 'team_fouls_total' : 'fouls_total';
        } elseif (preg_match('/\b(yellow card|yellow cards|cartao amarelo|cartoes amarelos|tarjeta amarilla|tarjetas amarillas)\b/u', $haystack)) {
            $marketKey = $teamMarket ? 'team_yellow_cards_total' : 'yellow_cards_total';
        } elseif (preg_match('/\b(card|cards|cartao|cartoes|tarjeta|tarjetas)\b/u', $haystack)) {
            $marketKey = $teamMarket ? 'team_cards_total' : 'cards_total';
        } elseif (preg_match('/\b(both teams to score|ambas.*marcam|ambos.*marcan|btts)\b/u', $haystack)) {
            $marketKey = 'btts';
        } elseif (preg_match('/\b(match result|resultado final|resultado da partida|vencedor|winner|moneyline)\b/u', $haystack)) {
            $marketKey = 'match_result';
        } elseif (preg_match('/\b(goal|goals|gol|gols|goles)\b/u', $haystack)) {
            $marketKey = $teamMarket ? 'team_goals_total' : 'goals_total';
        }

        $side = '';
        $line = null;
        if (preg_match('/\b(over|mais de|acima de|mas de|más de)\s*([0-9]+(?:[.,][0-9]+)?)/u', $haystack, $m)) {
            $side = 'over';
            $line = (float)str_replace(',', '.', $m[2]);
        } elseif (preg_match('/\b(under|menos de|abaixo de)\s*([0-9]+(?:[.,][0-9]+)?)/u', $haystack, $m)) {
            $side = 'under';
            $line = (float)str_replace(',', '.', $m[2]);
        }

        $targetTeam = self::selectedTeam($selection, $left, $right);
        if ($targetTeam === null) {
            $targetTeam = self::selectedTeam($market, $left, $right);
        }

        if ($targetTeam !== null && in_array($side, ['over','under'], true)) {
            $marketKey = match ($marketKey) {
                'goals_total' => 'team_goals_total',
                'corners_total' => 'team_corners_total',
                'fouls_total' => 'team_fouls_total',
                'yellow_cards_total' => 'team_yellow_cards_total',
                'cards_total' => 'team_cards_total',
                default => $marketKey,
            };
        } elseif (
            $targetTeam === null
            && in_array($side, ['over','under'], true)
            && in_array($marketKey, ['goals_total','corners_total','fouls_total','yellow_cards_total','cards_total'], true)
            && (self::teamQualifier($selection) !== '' || self::teamQualifier($market) !== '')
        ) {
            $marketKey = 'unsupported';
        }

        if ($marketKey === 'btts') {
            $selectionKey = self::key($selection);
            if (preg_match('/\b(sim|yes|si|sí)\b/u', $selectionKey)) {
                $side = 'yes';
            } elseif (preg_match('/\b(nao|no)\b/u', $selectionKey)) {
                $side = 'no';
            }
        }

        if ($marketKey === 'match_result') {
            $selectionKey = self::key($selection);
            if (preg_match('/\b(empate|draw|x)\b/u', $selectionKey)) {
                $side = 'draw';
            } elseif ($targetTeam !== null && $left !== null && self::sameTeam($targetTeam, $left)) {
                $side = 'home';
            } elseif ($targetTeam !== null && $right !== null && self::sameTeam($targetTeam, $right)) {
                $side = 'away';
            }
        }

        if (str_starts_with($marketKey, 'team_') && $targetTeam === null) {
            $targetTeam = self::selectedTeam($market, $left, $right);
        }

        return [
            'position' => $position,
            'sport' => mb_substr($sport, 0, 64),
            'match' => mb_substr($match, 0, 255),
            'league' => mb_substr($league, 0, 190),
            'event_date' => self::date($raw['date'] ?? null),
            'home_hint' => $left,
            'away_hint' => $right,
            'market_text' => mb_substr($market, 0, 255),
            'selection_text' => mb_substr($selection, 0, 255),
            'market_key' => $marketKey,
            'side' => $side,
            'line' => $line,
            'target_team' => $targetTeam,
            'odds' => self::decimal($raw['odd'] ?? null),
        ];
    }

    private static function matchSides(string $match): array
    {
        $patterns = [
            '/\s+[x×]\s+/iu',
            '/\s+vs\.?\s+/iu',
            '/\s+v\s+/iu',
            '/\s+[-\x{2013}\x{2014}\x{2212}]\s+/u',
        ];
        foreach ($patterns as $pattern) {
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

    private static function selectedTeam(string $text, ?string $left, ?string $right): ?string
    {
        if ($left !== null && self::containsTeam($text, $left)) {
            return $left;
        }
        if ($right !== null && self::containsTeam($text, $right)) {
            return $right;
        }

        $qualifier=self::teamQualifier($text);
        if ($qualifier==='' || ($left===null && $right===null)) {
            return null;
        }

        $scores=[];
        if ($left!==null)$scores[]=['team'=>$left,'score'=>self::teamSimilarity($qualifier,$left)];
        if ($right!==null)$scores[]=['team'=>$right,'score'=>self::teamSimilarity($qualifier,$right)];
        usort($scores,static fn(array $a,array $b): int=>$b['score']<=>$a['score']);
        $best=$scores[0]??null;
        $second=(float)($scores[1]['score']??0.0);
        if($best===null || (float)$best['score']<0.64 || ((float)$best['score']-$second)<0.12){
            return null;
        }
        return (string)$best['team'];
    }

    private static function teamQualifier(string $text): string
    {
        $key=self::key($text);
        if($key==='')return '';
        $key=preg_replace('/\\b(over|under|mais|menos|acima|abaixo|mas|de|do|da|dos|das|no|na|nos|nas|total|jogo|partida|match|team|equipe|time|equipo|goal|goals|gol|gols|goles|corner|corners|escanteio|escanteios|corneres|foul|fouls|falta|faltas|yellow|amarelo|amarelos|card|cards|cartao|cartoes|tarjeta|tarjetas)\\b/u',' ',$key)??$key;
        $key=preg_replace('/[0-9]+(?:[.,][0-9]+)?|[+.,-]+/u',' ',$key)??$key;
        return trim(preg_replace('/\\s+/u',' ',$key)??$key);
    }

    private static function teamSimilarity(string $a,string $b): float
    {
        $a=self::compactKey($a);
        $b=self::compactKey($b);
        if($a===''||$b==='')return 0.0;
        if($a===$b)return 1.0;
        similar_text($a,$b,$percent);
        return $percent/100;
    }

    private static function containsTeam(string $text, string $team): bool
    {
        $textKey = self::key($text);
        $teamKey = self::key($team);
        return $teamKey !== '' && str_contains(' ' . $textKey . ' ', ' ' . $teamKey . ' ');
    }

    public static function sameTeam(string $a, string $b): bool
    {
        $aKey = self::compactKey($a);
        $bKey = self::compactKey($b);
        if ($aKey === '' || $bKey === '') {
            return false;
        }
        if ($aKey === $bKey) {
            return true;
        }
        similar_text($aKey, $bKey, $percent);
        return $percent >= 86.0;
    }

    private static function date(mixed $value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        $tz = new \DateTimeZone('America/Sao_Paulo');
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d/m/y', '!d-m-y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw, $tz);
            if ($date instanceof \DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        if (preg_match('/\b(\d{1,2})[\/-](\d{1,2})\b/', $raw, $m)) {
            $year = (int)(new \DateTimeImmutable('now', $tz))->format('Y');
            $candidate = \DateTimeImmutable::createFromFormat('!j/n/Y', $m[1] . '/' . $m[2] . '/' . $year, $tz);
            if ($candidate instanceof \DateTimeImmutable) {
                return $candidate->format('Y-m-d');
            }
        }

        return null;
    }

    private static function decimal(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace(',', '.', preg_replace('/[^0-9,.]/', '', $raw) ?? '');
        if (!is_numeric($raw)) {
            return null;
        }
        $number = (float)$raw;
        return $number > 0 ? $number : null;
    }

    private static function key(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = is_string($ascii) ? strtolower($ascii) : $value;
        $value = preg_replace('/[^a-z0-9.,+ -]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function compactKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', self::key($value)) ?? '';
    }
}
