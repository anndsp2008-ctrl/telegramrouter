<?php declare(strict_types=1);

namespace App\Results;

final class SettlementEngine
{
    public const PENDING = 'PENDING';
    public const GREEN = 'GREEN';
    public const RED = 'RED';
    public const VOID = 'VOID';
    public const HALF_GREEN = 'HALF_GREEN';
    public const HALF_RED = 'HALF_RED';
    public const PENDING_REVIEW = 'PENDING_REVIEW';

    public static function settle(array $bet, array $stats): array
    {
        $market = strtolower(trim((string)($bet['market'] ?? '')));
        $side = strtolower(trim((string)($bet['side'] ?? '')));
        $line = isset($bet['line']) ? (float)$bet['line'] : null;
        $odds = (float)($bet['odds'] ?? 0);
        $stake = 10.0;

        if ($market === '' || $side === '' || $line === null || $odds <= 1) {
            return self::result(self::PENDING_REVIEW, 0, 'Dados da aposta insuficientes.');
        }

        $value = self::marketValue($market, $stats);
        if ($value === null) {
            return self::result(self::PENDING_REVIEW, 0, 'Estatística necessária indisponível.');
        }

        if (!in_array($side, ['over', 'under'], true)) {
            return self::result(self::PENDING_REVIEW, 0, 'Lado de mercado ainda não suportado.');
        }

        if (self::isQuarterLine($line)) {
            [$a, $b] = self::splitAsianLine($line);
            $r1 = self::gradeTotal($side, $a, $value);
            $r2 = self::gradeTotal($side, $b, $value);
            return self::combineAsian($r1, $r2, $stake, $odds);
        }

        $grade = self::gradeTotal($side, $line, $value);
        return self::financialResult($grade, $stake, $odds);
    }

    private static function marketValue(string $market, array $stats): ?float
    {
        return match ($market) {
            'goals_total', 'total_goals' => self::num($stats['goals_total'] ?? null),
            'corners_total', 'total_corners' => self::num($stats['corners_total'] ?? null),
            'cards_total', 'total_cards' => self::num($stats['cards_total'] ?? null),
            'yellow_cards_total' => self::num($stats['yellow_cards_total'] ?? null),
            'fouls_total', 'total_fouls' => self::num($stats['fouls_total'] ?? null),
            default => null,
        };
    }

    private static function gradeTotal(string $side, float $line, float $value): string
    {
        if (abs($value - $line) < 0.00001) return self::VOID;
        if ($side === 'over') return $value > $line ? self::GREEN : self::RED;
        return $value < $line ? self::GREEN : self::RED;
    }

    private static function combineAsian(string $a, string $b, float $stake, float $odds): array
    {
        if ($a === self::GREEN && $b === self::GREEN) return self::financialResult(self::GREEN, $stake, $odds);
        if ($a === self::RED && $b === self::RED) return self::financialResult(self::RED, $stake, $odds);
        if ($a === self::VOID && $b === self::VOID) return self::financialResult(self::VOID, $stake, $odds);
        if (($a === self::GREEN && $b === self::VOID) || ($b === self::GREEN && $a === self::VOID)) {
            return self::result(self::HALF_GREEN, round(($stake / 2) * ($odds - 1), 4));
        }
        if (($a === self::RED && $b === self::VOID) || ($b === self::RED && $a === self::VOID)) {
            return self::result(self::HALF_RED, -round($stake / 2, 4));
        }
        return self::result(self::PENDING_REVIEW, 0, 'Combinação asiática inesperada.');
    }

    private static function financialResult(string $grade, float $stake, float $odds): array
    {
        return match ($grade) {
            self::GREEN => self::result(self::GREEN, round($stake * ($odds - 1), 4)),
            self::RED => self::result(self::RED, -$stake),
            self::VOID => self::result(self::VOID, 0),
            default => self::result(self::PENDING_REVIEW, 0),
        };
    }

    private static function isQuarterLine(float $line): bool
    {
        $fraction = abs($line - floor($line));
        return abs($fraction - 0.25) < 0.00001 || abs($fraction - 0.75) < 0.00001;
    }

    private static function splitAsianLine(float $line): array
    {
        if (abs(($line - floor($line)) - 0.25) < 0.00001) return [$line - 0.25, $line + 0.25];
        return [$line - 0.25, $line + 0.25];
    }

    private static function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private static function result(string $status, float $profit, ?string $reason = null): array
    {
        return ['status' => $status, 'profit_units' => $profit, 'stake_units' => 10.0, 'reason' => $reason];
    }
}
