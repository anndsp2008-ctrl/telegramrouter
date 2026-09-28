<?php declare(strict_types=1);

namespace App\Reporting;

final class SettlementEngine
{
    public const PENDING = 'PENDING';
    public const GREEN = 'GREEN';
    public const RED = 'RED';
    public const VOID = 'VOID';
    public const HALF_GREEN = 'HALF_GREEN';
    public const HALF_RED = 'HALF_RED';
    public const REVIEW = 'REVIEW';

    public static function settle(array $leg, array $stats): array
    {
        $market = strtolower(trim((string)($leg['market_key'] ?? 'unsupported')));
        $side = strtolower(trim((string)($leg['side'] ?? '')));
        $line = isset($leg['line_value']) && $leg['line_value'] !== null
            ? (float)$leg['line_value']
            : (isset($leg['line']) && $leg['line'] !== null ? (float)$leg['line'] : null);
        $odds = self::num($leg['odds'] ?? null);

        if ($market === 'unsupported') {
            return self::result(self::REVIEW, null, null, 'Mercado não suportado automaticamente.');
        }

        if (in_array($market, ['cards_total','team_cards_total'], true)) {
            return self::result(
                self::REVIEW,
                null,
                null,
                'Mercado de cartões genérico depende da regra de contagem da casa.'
            );
        }

        if ($market === 'match_result') {
            $home = self::num($stats['goals_home'] ?? null);
            $away = self::num($stats['goals_away'] ?? null);
            if ($home === null || $away === null || !in_array($side, ['home','away','draw'], true)) {
                return self::result(self::REVIEW, null, null, 'Dados insuficientes para resultado da partida.');
            }
            $winner = $home > $away ? 'home' : ($away > $home ? 'away' : 'draw');
            return self::simpleGrade($winner === $side ? self::GREEN : self::RED, $odds, $home . '-' . $away);
        }

        if ($market === 'btts') {
            $home = self::num($stats['goals_home'] ?? null);
            $away = self::num($stats['goals_away'] ?? null);
            if ($home === null || $away === null || !in_array($side, ['yes','no'], true)) {
                return self::result(self::REVIEW, null, null, 'Dados insuficientes para ambas marcam.');
            }
            $yes = $home > 0 && $away > 0;
            $won = ($side === 'yes' && $yes) || ($side === 'no' && !$yes);
            return self::simpleGrade($won ? self::GREEN : self::RED, $odds, $yes ? 'yes' : 'no');
        }

        if ($line === null || !in_array($side, ['over','under'], true)) {
            return self::result(self::REVIEW, null, null, 'Linha ou lado do mercado inválido.');
        }

        $value = self::marketValue($market, $leg, $stats);
        if ($value === null) {
            return self::result(self::REVIEW, null, null, 'Estatística necessária indisponível.');
        }

        if (self::isQuarterLine($line)) {
            [$a, $b] = self::splitAsianLine($line);
            $first = self::gradeTotal($side, $a, $value);
            $second = self::gradeTotal($side, $b, $value);
            return self::combineAsian($first, $second, $odds, $value);
        }

        return self::simpleGrade(self::gradeTotal($side, $line, $value), $odds, $value);
    }

    private static function marketValue(string $market, array $leg, array $stats): ?float
    {
        return match ($market) {
            'goals_total' => self::sum($stats['goals_home'] ?? null, $stats['goals_away'] ?? null),
            'corners_total' => self::sum($stats['corners_home'] ?? null, $stats['corners_away'] ?? null),
            'fouls_total' => self::sum($stats['fouls_home'] ?? null, $stats['fouls_away'] ?? null),
            'yellow_cards_total' => self::sum($stats['yellow_cards_home'] ?? null, $stats['yellow_cards_away'] ?? null),
            'cards_total' => self::sum4(
                $stats['yellow_cards_home'] ?? null,
                $stats['yellow_cards_away'] ?? null,
                $stats['red_cards_home'] ?? null,
                $stats['red_cards_away'] ?? null
            ),
            'team_goals_total' => self::teamValue($leg, $stats, 'goals'),
            'team_corners_total' => self::teamValue($leg, $stats, 'corners'),
            'team_fouls_total' => self::teamValue($leg, $stats, 'fouls'),
            'team_yellow_cards_total' => self::teamValue($leg, $stats, 'yellow_cards'),
            'team_cards_total' => self::teamCards($leg, $stats),
            default => null,
        };
    }

    private static function teamValue(array $leg, array $stats, string $prefix): ?float
    {
        $target = trim((string)($leg['target_team'] ?? ''));
        $homeTeam = trim((string)($stats['home_team'] ?? ''));
        $awayTeam = trim((string)($stats['away_team'] ?? ''));

        if ($target === '' || $homeTeam === '' || $awayTeam === '') {
            return null;
        }

        if (TicketNormalizer::sameTeam($target, $homeTeam)) {
            return self::num($stats[$prefix . '_home'] ?? null);
        }
        if (TicketNormalizer::sameTeam($target, $awayTeam)) {
            return self::num($stats[$prefix . '_away'] ?? null);
        }
        return null;
    }

    private static function teamCards(array $leg, array $stats): ?float
    {
        $target = trim((string)($leg['target_team'] ?? ''));
        $homeTeam = trim((string)($stats['home_team'] ?? ''));
        $awayTeam = trim((string)($stats['away_team'] ?? ''));

        if ($target === '' || $homeTeam === '' || $awayTeam === '') {
            return null;
        }

        if (TicketNormalizer::sameTeam($target, $homeTeam)) {
            return self::sum($stats['yellow_cards_home'] ?? null, $stats['red_cards_home'] ?? null);
        }
        if (TicketNormalizer::sameTeam($target, $awayTeam)) {
            return self::sum($stats['yellow_cards_away'] ?? null, $stats['red_cards_away'] ?? null);
        }
        return null;
    }

    private static function gradeTotal(string $side, float $line, float $value): string
    {
        if (abs($value - $line) < 0.000001) {
            return self::VOID;
        }
        if ($side === 'over') {
            return $value > $line ? self::GREEN : self::RED;
        }
        return $value < $line ? self::GREEN : self::RED;
    }

    private static function combineAsian(string $a, string $b, ?float $odds, float $observed): array
    {
        if ($a === self::GREEN && $b === self::GREEN) {
            return self::simpleGrade(self::GREEN, $odds, $observed);
        }
        if ($a === self::RED && $b === self::RED) {
            return self::simpleGrade(self::RED, $odds, $observed);
        }
        if ($a === self::VOID && $b === self::VOID) {
            return self::simpleGrade(self::VOID, $odds, $observed);
        }
        if (($a === self::GREEN && $b === self::VOID) || ($b === self::GREEN && $a === self::VOID)) {
            $factor = $odds !== null && $odds > 1 ? 1 + (($odds - 1) / 2) : null;
            return self::result(self::HALF_GREEN, $factor, $observed);
        }
        if (($a === self::RED && $b === self::VOID) || ($b === self::RED && $a === self::VOID)) {
            return self::result(self::HALF_RED, 0.5, $observed);
        }
        return self::result(self::REVIEW, null, $observed, 'Combinação asiática inesperada.');
    }

    private static function simpleGrade(string $status, ?float $odds, mixed $observed): array
    {
        $factor = match ($status) {
            self::GREEN => $odds !== null && $odds > 1 ? $odds : null,
            self::RED => 0.0,
            self::VOID => 1.0,
            default => null,
        };
        return self::result($status, $factor, $observed);
    }

    private static function isQuarterLine(float $line): bool
    {
        $fraction = abs($line - floor($line));
        return abs($fraction - 0.25) < 0.000001 || abs($fraction - 0.75) < 0.000001;
    }

    private static function splitAsianLine(float $line): array
    {
        return [$line - 0.25, $line + 0.25];
    }

    private static function sum(mixed $a, mixed $b): ?float
    {
        $a = self::num($a);
        $b = self::num($b);
        return $a !== null && $b !== null ? $a + $b : null;
    }

    private static function sum4(mixed $a, mixed $b, mixed $c, mixed $d): ?float
    {
        foreach ([$a,$b,$c,$d] as $value) {
            if (self::num($value) === null) {
                return null;
            }
        }
        return (float)$a + (float)$b + (float)$c + (float)$d;
    }

    private static function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private static function result(string $status, ?float $returnFactor, mixed $observed, ?string $reason = null): array
    {
        return [
            'status' => $status,
            'return_factor' => $returnFactor,
            'observed' => $observed,
            'reason' => $reason,
        ];
    }
}
