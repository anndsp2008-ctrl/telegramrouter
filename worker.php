<?php declare(strict_types=1);
require __DIR__.'/vendor/autoload.php'; require __DIR__.'/config/config.php'; require __DIR__.'/app/Database.php'; require __DIR__.'/app/Crypto.php'; require __DIR__.'/app/Repository.php'; require __DIR__.'/app/Transform.php'; require __DIR__.'/app/TelegramRouter.php';
use danog\MadelineProto\Settings; use App\TelegramRouter;
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$credentials=App\Repository::credentials();
$apiId=$credentials['telegram_api_id']??config('telegram_api_id'); $apiHash=$credentials['telegram_api_hash']??config('telegram_api_hash'); $session=$credentials['telegram_session']??config('telegram_session');
$enabled = config('telegram_enabled') || !empty($credentials['telegram_session']);
if (!$apiId || !$apiHash || !$session || !$enabled) { fwrite(STDERR,"Telegram worker desativado ou sem credenciais.\n"); exit(0); }
$pidFile=__DIR__.'/storage/worker.pid'; @file_put_contents($pidFile,(string)getmypid(),LOCK_EX);
register_shutdown_function(static function() use ($pidFile): void { if (is_file($pidFile) && trim((string)@file_get_contents($pidFile))===(string)getmypid()) @unlink($pidFile); });
$settings=new Settings; TelegramRouter::startAndLoop(__DIR__.'/storage/sessions/telegram.madeline', $settings);
