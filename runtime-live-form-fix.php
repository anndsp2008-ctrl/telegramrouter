<?php declare(strict_types=1);

$path = '/app/assets/live.js';
if (!is_file($path)) {
    fwrite(STDERR, "LIVE_FORM_ACTION_FIX_MISSING_FILE\n");
    exit(1);
}
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "LIVE_FORM_ACTION_FIX_READ_FAILED\n");
    exit(1);
}

$old = 'fetch(form.action || window.location.href,';
$new = 'fetch(form.getAttribute("action") || window.location.href,';

if (str_contains($source, $old)) {
    $source = str_replace($old, $new, $source, $count);
    if ($count < 1 || file_put_contents($path, $source) === false) {
        fwrite(STDERR, "LIVE_FORM_ACTION_FIX_WRITE_FAILED\n");
        exit(1);
    }
}

$verify = file_get_contents($path);
if (!is_string($verify) || !str_contains($verify, $new) || str_contains($verify, $old)) {
    fwrite(STDERR, "LIVE_FORM_ACTION_FIX_VERIFY_FAILED\n");
    exit(1);
}

echo "LIVE_FORM_ACTION_FIX_APPLIED\n";
