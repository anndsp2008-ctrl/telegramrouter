<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$jobId = (string)($argv[1] ?? '');
$jobDir = $base . '/storage/auth-jobs';
$jobIdSafe = preg_replace('/[^a-f0-9]/', '', $jobId) ?? '';
$jobFile = $jobDir . '/' . $jobIdSafe . '.json';

if ($jobIdSafe === '' || !is_file($jobFile)) {
    exit(1);
}

// Define a sessão isolada antes do bootstrap, que inicializa Auth::boot().
session_id('telegram_job_' . $jobIdSafe);
require $base . '/bootstrap.php';
require $base . '/app/TelegramAuth.php';

use App\TelegramAuth;

$job = json_decode((string)file_get_contents($jobFile), true) ?: [];
$write = static function (array $patch) use ($jobFile, &$job): void {
    $job = array_merge($job, $patch, ['updated_at' => time()]);
    file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE), LOCK_EX);
};

try {
    $result = TelegramAuth::begin((string)$job['api_id'], (string)$job['api_hash'], (string)$job['phone']);
    $write(['status' => $result === 'ready' ? 'ready' : 'code']);
} catch (Throwable $e) {
    $write(['status' => 'error', 'error' => \App\ErrorTranslator::message($e, 'o início da conexão Telegram')]);
}
