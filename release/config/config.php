<?php declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim($value, " \"'"));
    }
}

function env(string $key, ?string $default = null): ?string { $value = getenv($key); return $value === false ? $default : $value; }
function dataHoraBrasil(mixed $value): string { $text=trim((string)$value); if($text==='') return 'Sem registro'; try { $date=new DateTimeImmutable($text,new DateTimeZone('UTC')); return $date->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i:s'); } catch (Throwable) { return $text; } }
function config(string $key, mixed $default = null): mixed {
    static $values = null;
    if ($values === null) {
        $values = [
            'app_key' => env('APP_KEY', ''), 'app_env' => env('APP_ENV', 'production'),
            'app_url' => env('APP_URL', ''), 'db_host' => env('DB_HOST', 'localhost'),
            'db_port' => env('DB_PORT', '3306'), 'db_name' => env('DB_NAME', ''),
            'db_user' => env('DB_USER', ''), 'db_pass' => env('DB_PASS', ''),
            'panel_username' => env('PANEL_USERNAME', 'admin'),
            'panel_password_hash' => env('PANEL_PASSWORD_HASH', ''),
            'telegram_api_id' => env('TELEGRAM_API_ID', ''), 'telegram_api_hash' => env('TELEGRAM_API_HASH', ''),
            'telegram_session' => env('TELEGRAM_SESSION', ''), 'telegram_owner_user_id' => env('TELEGRAM_OWNER_USER_ID', ''),
            'telegram_enabled' => filter_var(env('TELEGRAM_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN),
        ];
    }
    return $values[$key] ?? $default;
}
