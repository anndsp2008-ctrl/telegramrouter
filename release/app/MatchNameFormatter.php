<?php declare(strict_types=1);

namespace App;

final class MatchNameFormatter
{
    /**
     * Canonical public format for a matchup: "Time A vs Time B".
     * Input remains tolerant to legacy/source separators.
     */
    public static function normalize(string $value): string
    {
        $value = trim(preg_replace('/[ \t]{2,}/u', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }

        [$left, $right] = self::split($value);
        if ($left === null || $right === null) {
            return $value;
        }

        return $left . ' vs ' . $right;
    }

    /** @return array{0:?string,1:?string} */
    public static function split(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [null, null];
        }

        foreach ([
            '/\s+[x×]\s+/iu',
            '/\s+vs\.?\s+/iu',
            '/\s+v\s+/iu',
            '/\s+[-\x{2013}\x{2014}\x{2212}]\s+/u',
        ] as $pattern) {
            $parts = preg_split($pattern, $value, 2);
            if (!is_array($parts) || count($parts) !== 2) {
                continue;
            }

            $left = trim((string)$parts[0]);
            $right = trim((string)$parts[1]);
            if ($left !== '' && $right !== '') {
                return [$left, $right];
            }
        }

        return [null, null];
    }
}
