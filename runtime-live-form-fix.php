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

// The live updater refreshes the whole page every ~1.5s. On the integrations
// workspace this destroys/rebuilds the accordion continuously, causing visible
// flicker and replacing the transformed provider cards. Keep async POST
// save/test behavior, but disable background GET polling only on integrations.
$pollOld = "const response = await fetch(window.location.href, { headers: { 'X-Atualizacao-Assincrona': '1' }, credentials: 'same-origin', cache: 'no-store' });";
$pollNew = "if (new URLSearchParams(window.location.search).get('page') === 'integrations') return; const response = await fetch(window.location.href, { headers: { 'X-Atualizacao-Assincrona': '1' }, credentials: 'same-origin', cache: 'no-store' });";

if (str_contains($source, $old)) {
    $source = str_replace($old, $new, $source, $count);
    if ($count < 1 || file_put_contents($path, $source) === false) {
        fwrite(STDERR, "LIVE_FORM_ACTION_FIX_WRITE_FAILED\n");
        exit(1);
    }
}

if (str_contains($source, $pollOld)) {
    $source = str_replace($pollOld, $pollNew, $source, $pollCount);
    if ($pollCount < 1 || file_put_contents($path, $source) === false) {
        fwrite(STDERR, "LIVE_INTEGRATIONS_POLL_FIX_WRITE_FAILED\n");
        exit(1);
    }
}

$verify = file_get_contents($path);
if (!is_string($verify) || !str_contains($verify, $new) || str_contains($verify, $old)) {
    fwrite(STDERR, "LIVE_FORM_ACTION_FIX_VERIFY_FAILED\n");
    exit(1);
}
if (!str_contains($verify, $pollNew) || str_contains($verify, $pollOld)) {
    fwrite(STDERR, "LIVE_INTEGRATIONS_POLL_FIX_VERIFY_FAILED\n");
    exit(1);
}

echo "LIVE_FORM_ACTION_FIX_APPLIED\n";
echo "LIVE_INTEGRATIONS_POLL_DISABLED\n";
