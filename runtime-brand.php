<?php declare(strict_types=1);

/*
 * Canonical Telegram Router branding.
 * Runs after runtime patches so login, dashboard, favicon and PWA
 * always use the same project mark.
 */

$root = __DIR__;
$brandDir = $root.'/assets/brand';
$mark = $brandDir.'/mark.svg';
$maskable = $brandDir.'/mark-maskable.svg';

if (!is_file($mark) || filesize($mark) < 100) {
    fwrite(STDERR, "BRAND_CANONICAL_MARK_MISSING\n");
    exit(1);
}
if (!is_file($maskable) || filesize($maskable) < 100) {
    fwrite(STDERR, "BRAND_MASKABLE_MARK_MISSING\n");
    exit(1);
}

// Legacy runtime targets now become aliases of the canonical mark.
if (!@copy($mark, $root.'/favicon.svg')) {
    fwrite(STDERR, "BRAND_FAVICON_WRITE_FAILED\n");
    exit(1);
}
if (!@copy($mark, $root.'/assets/tmr-logo.svg')) {
    fwrite(STDERR, "BRAND_LOGIN_LOGO_WRITE_FAILED\n");
    exit(1);
}

$manifestPath = $root.'/manifest.webmanifest';
$manifest = [];
if (is_file($manifestPath)) {
    $decoded = json_decode((string)file_get_contents($manifestPath), true);
    if (is_array($decoded)) $manifest = $decoded;
}
$manifest['name'] = 'Telegram Router';
$manifest['short_name'] = 'Telegram Router';
$manifest['description'] = 'Automação inteligente para roteamento de mensagens do Telegram.';
$manifest['start_url'] = '/';
$manifest['scope'] = '/';
$manifest['display'] = 'standalone';
$manifest['background_color'] = '#0B1220';
$manifest['theme_color'] = '#0B1220';
$manifest['icons'] = [
    [
        'src' => '/assets/brand/mark.svg?v=1',
        'sizes' => 'any',
        'type' => 'image/svg+xml',
        'purpose' => 'any',
    ],
    [
        'src' => '/assets/brand/mark-maskable.svg?v=1',
        'sizes' => 'any',
        'type' => 'image/svg+xml',
        'purpose' => 'maskable',
    ],
];

$json = json_encode($manifest, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
if (!is_string($json) || @file_put_contents($manifestPath, $json."\n") === false) {
    fwrite(STDERR, "BRAND_MANIFEST_WRITE_FAILED\n");
    exit(1);
}

$headBrand = '<link rel="stylesheet" href="/assets/brand/brand.css?v=1">'
    .'<link rel="stylesheet" href="/assets/brand/mobile-shell.css?v=2">'
    .'<link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">'
    .'<link rel="manifest" href="/manifest.webmanifest?v=2">'
    .'<meta name="theme-color" content="#0B1220">';

foreach (['login.php','index.php','connect.php','reset.php','install.php'] as $page) {
    $path = $root.'/'.$page;
    if (!is_file($path)) continue;

    $html = (string)file_get_contents($path);

    // Remove previous brand-specific head tags so each page has one source of truth.
    $html = preg_replace('~<link\b[^>]*rel=["\'](?:shortcut\s+)?icon["\'][^>]*>~i', '', $html) ?? $html;
    $html = preg_replace('~<link\b[^>]*rel=["\']manifest["\'][^>]*>~i', '', $html) ?? $html;
    $html = preg_replace('~<link\b[^>]*href=["\']/assets/brand/(?:brand|mobile-shell)\.css[^"\']*["\'][^>]*>~i', '', $html) ?? $html;
    $html = preg_replace('~<meta\b[^>]*name=["\']theme-color["\'][^>]*>~i', '', $html) ?? $html;

    if (str_contains($html, '</head>')) {
        $html = str_replace('</head>', $headBrand.'</head>', $html);
    }

    // The sidebar symbol is replaced with the exact same mark used by login/PWA/favicon.
    $html = preg_replace(
        '~<span class="saas-logo(?:\s+[^"]*)?">.*?</span>~su',
        '<span class="saas-logo brand-mark"><img src="/assets/brand/mark.svg?v=1" alt="" aria-hidden="true"></span>',
        $html
    ) ?? $html;

    // Normalize legacy runtime references and cache versions.
    $html = str_replace(
        ['/favicon.svg?v=1','/favicon.svg?v=2','/manifest.webmanifest?v=1'],
        ['/favicon.svg?v=3','/favicon.svg?v=3','/manifest.webmanifest?v=2'],
        $html
    );

    if (@file_put_contents($path, $html) === false) {
        fwrite(STDERR, "BRAND_PAGE_WRITE_FAILED {$page}\n");
        exit(1);
    }
}

echo "BRAND_CANONICAL_APPLIED\n";
