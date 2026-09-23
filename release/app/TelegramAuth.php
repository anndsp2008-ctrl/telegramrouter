<?php declare(strict_types=1);
namespace App;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;

final class TelegramAuth
{
    private static function sessionFile(): string
    {
        $dir = dirname(__DIR__) . '/storage/sessions';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Não foi possível criar storage/sessions.');
        if (!is_writable($dir)) throw new \RuntimeException('A pasta storage/sessions não tem permissão de escrita.');
        return $dir . '/telegram.madeline';
    }

    private static function api(): API
    {
        $apiId = (int) ($_SESSION['telegram_api_id'] ?? config('telegram_api_id', '0'));
        $apiHash = trim((string) ($_SESSION['telegram_api_hash'] ?? config('telegram_api_hash', '')));
        if ($apiId <= 0 || $apiHash === '') throw new \RuntimeException('API ID ou API Hash inválidos. Confira os dados em my.telegram.org.');
        $settings = new Settings;
        $settings->setAppInfo((new AppInfo)->setApiId($apiId)->setApiHash($apiHash));
        return new API(self::sessionFile(), $settings);
    }

    public static function begin(string $apiId, string $apiHash, string $phone): string
    {
        Auth::boot();
        $apiId = trim($apiId); $apiHash = trim($apiHash); $phone = trim($phone);
        if (!ctype_digit($apiId) || (int)$apiId <= 0) throw new \InvalidArgumentException('O API ID deve ser numérico.');
        if ($apiHash === '' || strlen($apiHash) < 20) throw new \InvalidArgumentException('O API Hash parece incompleto.');
        if (!preg_match('/^\+[1-9][0-9]{7,15}$/', preg_replace('/[\s().-]+/', '', $phone))) throw new \InvalidArgumentException('Informe o telefone no formato internacional, por exemplo +5511999999999.');
        $stored = Repository::credentials();
        if (empty($stored['telegram_session'])) self::clearPendingSession();
        $_SESSION['telegram_api_id'] = $apiId; $_SESSION['telegram_api_hash'] = $apiHash; $_SESSION['telegram_phone'] = $phone;
        if (is_file(self::sessionFile()) || is_dir(self::sessionFile())) {
            try {
                $existing = self::api();
                $existing->getSelf();
                self::finish($existing);
                return 'ready';
            } catch (\Throwable) {
                self::clearPendingSession();
                $_SESSION['telegram_api_id'] = $apiId; $_SESSION['telegram_api_hash'] = $apiHash; $_SESSION['telegram_phone'] = $phone;
            }
        }
        try { self::api()->phoneLogin($phone); } catch (\Throwable $e) { self::cancel(); throw new \RuntimeException('O Telegram não aceitou o início do login: '.self::safeMessage($e), 0, $e); }
        $_SESSION['telegram_auth_step'] = 'code';
        Repository::saveCredential('telegram_api_id', $apiId); Repository::saveCredential('telegram_api_hash', $apiHash);
        return 'code';
    }

    public static function confirmCode(string $code): string
    {
        Auth::boot();
        if (($_SESSION['telegram_auth_step'] ?? '') !== 'code') throw new \RuntimeException('O código expirou. Inicie a conexão novamente.');
        if (!preg_match('/^[0-9]{3,8}$/', trim($code))) throw new \InvalidArgumentException('Informe somente os números do código recebido.');
        try { $authorization = self::api()->completePhoneLogin(trim($code)); } catch (\Throwable $e) { throw new \RuntimeException('O Telegram rejeitou o código: '.self::safeMessage($e), 0, $e); }
        if (($authorization['_'] ?? '') === 'account.password') { $_SESSION['telegram_auth_step'] = 'password'; return 'password'; }
        return self::finish(self::api());
    }

    public static function confirmPassword(string $password): string
    {
        Auth::boot();
        if (($_SESSION['telegram_auth_step'] ?? '') !== 'password') throw new \RuntimeException('A etapa de 2FA não está pendente.');
        if ($password === '') throw new \InvalidArgumentException('Informe a senha 2FA.');
        try { self::api()->complete2faLogin($password); } catch (\Throwable $e) { throw new \RuntimeException('A senha 2FA foi rejeitada: '.self::safeMessage($e), 0, $e); }
        return self::finish(self::api());
    }

    private static function finish(API $api): string
    {
        $self = $api->getSelf();
        Repository::saveCredential('telegram_owner_user_id', (string)($self['id'] ?? ''));
        Repository::saveCredential('telegram_phone', (string)($self['phone'] ?? ($_SESSION['telegram_phone'] ?? '')));
        // MadelineProto v8 persiste a sessão no arquivo informado ao construtor da API.
        // Não existe exportSession() nessa versão; o worker reutiliza o mesmo arquivo.
        Repository::saveCredential('telegram_session', 'file:storage/sessions/telegram.madeline');
        unset($_SESSION['telegram_api_id'], $_SESSION['telegram_api_hash'], $_SESSION['telegram_phone'], $_SESSION['telegram_auth_step']);
        return 'ready';
    }

    private static function safeMessage(\Throwable $e): string
    {
        $message = preg_replace('/(api[_ -]?hash|phone|code|password|token)\s*[:=]\s*\S+/i', '$1: [oculto]', $e->getMessage()) ?? $e->getMessage();
        return ErrorTranslator::message($e, 'a autenticação da conta Telegram');
    }

    public static function cancel(): void
    { self::clearPendingSession(); }

    public static function disconnect(): void
    {
        Auth::boot();
        $pidFile = dirname(__DIR__) . '/storage/worker.pid';
        if (is_file($pidFile)) {
            $pid = (int) trim((string) @file_get_contents($pidFile));
            if ($pid > 1) {
                if (function_exists('posix_kill')) @posix_kill($pid, 15);
                elseif (function_exists('exec')) @exec('kill ' . $pid . ' 2>/dev/null');
            }
            @unlink($pidFile);
        }
        $sessionPath = dirname(__DIR__) . '/storage/sessions/telegram.madeline';
        if (function_exists('exec')) @exec('pkill -TERM -f ' . escapeshellarg('MadelineProto.*' . $sessionPath) . ' 2>/dev/null');
        foreach (['telegram_session', 'telegram_owner_user_id', 'telegram_phone'] as $key) Repository::saveCredential($key, '');
        self::clearPendingSession();
        $dir = $sessionPath;
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            @rmdir($dir);
        } elseif (is_file($dir)) @unlink($dir);
        try { Database::pdo()->exec("UPDATE worker_status SET status='desconectado', last_activity_at=NOW(), last_error=NULL WHERE worker_key='telegram-global'"); } catch (\Throwable) {}
    }

    private static function clearPendingSession(): void
    {
        unset($_SESSION['telegram_api_id'], $_SESSION['telegram_api_hash'], $_SESSION['telegram_phone'], $_SESSION['telegram_auth_step']);
        $credentials = Repository::credentials();
        $file = dirname(__DIR__) . '/storage/sessions/telegram.madeline';
        if (empty($credentials['telegram_session'])) {
            if (is_file($file)) @unlink($file);
            if (is_dir($file)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($file, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                @rmdir($file);
            }
        }
    }
}
