<?php declare(strict_types=1);
namespace App;

final class ChatMatcher
{
    /** @param array<string,mixed> $peerInfo */
    public static function identifiers(int|string $chatId, array $peerInfo = []): array
    {
        $values = [(string)$chatId];
        foreach (['bot_api_id', 'chat_id', 'channel_id', 'user_id'] as $key) {
            if (isset($peerInfo[$key]) && (string)$peerInfo[$key] !== '') $values[] = (string)$peerInfo[$key];
        }
        foreach (['Chat', 'User'] as $section) {
            $data = $peerInfo[$section] ?? [];
            if (!is_array($data)) continue;
            foreach (['username', 'title', 'first_name', 'last_name'] as $key) {
                if (isset($data[$key]) && trim((string)$data[$key]) !== '') $values[] = (string)$data[$key];
            }
        }
        $names = [];
        foreach ($values as $value) {
            foreach (self::variants((string)$value) as $normalized) {
                if ($normalized !== '') $names[$normalized] = true;
            }
        }
        return array_keys($names);
    }

    /** @param array<int,string> $identifiers */
    public static function matches(array $identifiers, string $wanted): bool
    {
        foreach (self::variants($wanted) as $candidate) {
            if ($candidate !== '' && in_array($candidate, $identifiers, true)) return true;
        }
        return false;
    }

    /** @return array<int,string> */
    private static function variants(string $value): array
    {
        $normalized = self::normalize($value);
        if ($normalized === '') return [];
        $out = [$normalized => true];
        if (preg_match('/^-100(\d+)$/', $normalized, $m)) {
            $out[$m[1]] = true;
        } elseif (preg_match('/^\d+$/', $normalized)) {
            $out['-100'.$normalized] = true;
        }
        return array_keys($out);
    }

    public static function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('~^(?:https?://)?(?:t\.me|telegram\.me)/+~i', '', $value) ?? $value;
        $value = ltrim($value, '@');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
