<?php declare(strict_types=1);

$path = __DIR__ . '/app/TranslationService.php';
if (!is_file($path)) {
    error_log('TRANSLATION_HTTP_RETRY_INSPECT_SOURCE_MISSING');
    exit(0);
}

$lines = file($path, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    error_log('TRANSLATION_HTTP_RETRY_INSPECT_READ_FAILED');
    exit(0);
}

error_log('TRANSLATION_HTTP_RETRY_INSPECT_BEGIN');
foreach ($lines as $i => $line) {
    if (
        stripos($line, 'retryable') !== false ||
        stripos($line, 'curl_errno') !== false ||
        stripos($line, 'http_code') !== false ||
        stripos($line, 'retry') !== false
    ) {
        $from = max(0, $i - 3);
        $to = min(count($lines) - 1, $i + 3);
        for ($j = $from; $j <= $to; $j++) {
            error_log(sprintf('TRANSLATION_HTTP_RETRY_SRC %04d %s', $j + 1, $lines[$j]));
        }
    }
}
error_log('TRANSLATION_HTTP_RETRY_INSPECT_END');
