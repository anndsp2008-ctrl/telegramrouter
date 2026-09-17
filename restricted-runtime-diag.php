<?php
declare(strict_types=1);

$token = 'go6boENgGoAV9tvQGXzTaRwEaRpbuTaS';
if (!hash_equals($token, (string)($_GET['t'] ?? ''))) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
$path = __DIR__ . '/app/TelegramRouter.php';
if (!is_file($path)) {
    http_response_code(404);
    echo "TelegramRouter.php not found\n";
    @unlink(__FILE__);
    exit;
}

$lines = file($path, FILE_IGNORE_NEW_LINES);
$needles = [
    'CHAT_FORWARDS_RESTRICTED',
    'chat_forwards_restricted_v1',
    'sendMedia',
    'forwardMessages',
    'copyMessages',
    'downloadToFile',
    'downloadToDir',
    'getFileInfo',
    'sendPhoto',
    'sendDocument',
    'sendVideo',
    'sendAudio',
    'sendVoice',
];

$ranges = [];
foreach ($lines as $i => $line) {
    foreach ($needles as $needle) {
        if (stripos($line, $needle) !== false) {
            $start = max(0, $i - 30);
            $end = min(count($lines) - 1, $i + 40);
            $ranges[] = [$start, $end];
            break;
        }
    }
}

usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
$merged = [];
foreach ($ranges as [$start, $end]) {
    if (!$merged || $start > $merged[count($merged) - 1][1] + 1) {
        $merged[] = [$start, $end];
    } else {
        $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $end);
    }
}

foreach ($merged as [$start, $end]) {
    echo "===== lines " . ($start + 1) . '-' . ($end + 1) . " =====\n";
    for ($i = $start; $i <= $end; $i++) {
        printf("%05d: %s\n", $i + 1, $lines[$i]);
    }
}

@unlink(__FILE__);
