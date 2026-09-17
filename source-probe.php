<?php declare(strict_types=1);
$files = ['/app/index.php'];
foreach (glob('/app/assets/*.js') ?: [] as $f) $files[] = $f;
$patterns = [
    '/fetch\s*\(/i',
    '/\.action\b/i',
    '/elements.*action/i',
    '/FormData/i',
    '/save_integration/i',
    '/test_[a-z_]+/i',
    '/integration/i',
];
echo "INTEGRATIONS_SOURCE_PROBE_BEGIN\n";
foreach ($files as $file) {
    if (!is_file($file)) continue;
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) continue;
    foreach ($lines as $i => $line) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                $safe = preg_replace('/([A-Za-z0-9_]*(?:key|token|secret|hash)[A-Za-z0-9_]*\s*[=:]\s*)["\'][^"\']+["\']/i', '$1"[redacted]"', $line);
                echo basename($file).':'.($i+1).':'.$safe."\n";
                break;
            }
        }
    }
}
echo "INTEGRATIONS_SOURCE_PROBE_END\n";
