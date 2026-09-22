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

$headBrand = '<link rel="stylesheet" href="/assets/brand/brand.css?v=2">'
    .'<link rel="stylesheet" href="/assets/brand/mobile-shell.css?v=2">'
    .'<link rel="stylesheet" href="/assets/brand/integrations-v10.css?v=8&workers-dot=5">'
    .'<link rel="stylesheet" href="/assets/brand/activity-status-badges.css?v=4">'
    .'<link rel="stylesheet" href="/assets/brand/horizontal-scrollbar.css?v=1">'
    .'<link rel="stylesheet" href="/assets/brand/mobile-visual-audit.css?v=15">'
    .'<link rel="stylesheet" href="/assets/brand/service-status-responsive.css?v=1">'
    .'<link rel="stylesheet" href="/assets/brand/connect-responsive.css?v=1">'
    .'<link rel="stylesheet" href="/assets/brand/orchestration-responsive.css?v=1">'
    .'<link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">'
    .'<link rel="manifest" href="/manifest.webmanifest?v=2">'
    .'<meta name="theme-color" content="#0B1220">';

foreach (['login.php','index.php','connect.php','reset.php','install.php'] as $page) {
    $path = $root.'/'.$page;
    if (!is_file($path)) continue;

    $html = (string)file_get_contents($path);

    if ($page === 'login.php') {
        // The PWA pre-caches the old unversioned /assets/tmr-logo.svg using
        // cache-first. A unique query string makes the login fetch the current
        // canonical mark copied above, without changing any other assets.
        $unversionedLogo = 'src="/assets/tmr-logo.svg"';
        $versionedLogo = 'src="/assets/tmr-logo.svg?v=4"';
        if (substr_count($html, $unversionedLogo) === 1) {
            $html = str_replace($unversionedLogo, $versionedLogo, $html);
        } elseif (substr_count($html, $versionedLogo) !== 1) {
            fwrite(STDERR, "BRAND_LOGIN_LOGO_ANCHOR_CHANGED\n");
            exit(1);
        }
    }

    if ($page === 'reset.php') {
        // Reset is restored independently of index.php. Align ONLY its topbar
        // after snapshot extraction; leave every reset action and safety guard intact.
        $legacyHeader = <<<'HTML'
<header class="saas-topbar"><div class="saas-user"><span class="saas-avatar">AD</span><span><?=rh(function_exists('config')?config('panel_username'):'Administrador')?></span></div></header>
HTML;
        $canonicalHeader = <<<'HTML'
<header class="saas-topbar"><div class="tmr-app-header-brand" aria-label="TelegramRouter">
  <span class="tmr-app-brand-symbol"><img src="/assets/brand/mark.svg?v=1" alt=""></span>
  <span class="tmr-app-brand-name"><b>Telegram<span>Router</span></b><small>Conecte. Direcione. Automatize.</small></span>
</div><div class="saas-user"><span class="saas-avatar">AN</span><span><?=rh($resetTopbarGreeting)?></span><?php if($resetTopbarPhone!==''):?><span class="connected-phone">Telegram: <?=rh($resetTopbarPhone)?></span><?php endif; ?><form method="post" action="/"><input type="hidden" name="csrf" value="<?=rh(Auth::csrf())?>"><input type="hidden" name="action" value="logout"><button class="saas-logout" type="submit" aria-label="Sair" title="Sair"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M14 16l4-4-4-4"/><path d="M18 12H8"/></svg></button></form></div></header>
HTML;
        $legacyAnchor = '$totalItems=0; foreach($inventory as $item)$totalItems+=(int)($item[\'rows\']??0);';
        $resetVars = <<<'PHP'
$resetTopbarHour=(int)(new \DateTimeImmutable('now',new \DateTimeZone('America/Sao_Paulo')))->format('G');
$resetTopbarGreeting=($resetTopbarHour<12?'Bom dia':($resetTopbarHour<18?'Boa tarde':'Boa noite')).', Anderson';
$resetTopbarPhone='';
try {
    $resetHeaderCredentials=\App\Repository::credentials();
    $resetTopbarPhone=(string)($resetHeaderCredentials['telegram_phone']??'');
    if($resetTopbarPhone!=='' && $resetTopbarPhone[0]!=='+')$resetTopbarPhone='+'.$resetTopbarPhone;
} catch(\Throwable $ignored) {
    // The reset screen must remain available even if Telegram credentials are absent.
}
PHP;
        if (str_contains($html, $legacyHeader)) {
            if (substr_count($html, $legacyHeader)!==1 || substr_count($html, $legacyAnchor)!==1) {
                fwrite(STDERR, "BRAND_RESET_HEADER_ANCHOR_CHANGED\n"); exit(1);
            }
            $html = str_replace($legacyAnchor, $legacyAnchor."\n".$resetVars, $html);
            $html = str_replace($legacyHeader, $canonicalHeader, $html);
        } elseif (!str_contains($html, $canonicalHeader) || !str_contains($html, '$resetTopbarGreeting=')) {
            fwrite(STDERR, "BRAND_RESET_HEADER_UNKNOWN_STATE\n"); exit(1);
        }
        foreach ([
            '/assets/brand/mobile-app-header.css?v=2',
            '/assets/brand/logout-icon.css?v=1'
        ] as $stylesheet) {
            if (str_contains($html, $stylesheet)) continue;
            if (substr_count($html, '</head>')!==1) {
                fwrite(STDERR, "BRAND_RESET_HEADER_HEAD_MISSING\n"); exit(1);
            }
            $html = str_replace('</head>', '<link rel="stylesheet" href="'.$stylesheet.'"></head>', $html);
        }
    }

    if ($page === 'index.php') {
        // Railway's immutable pre-deploy contract verifies the v6 source
        // marker in runtime-integrations-ui.php. The branding patch runs after
        // that installer, so update only the final HTML URL to v7 here to
        // invalidate browsers' cached feedback script without changing the
        // service configuration or the provider integration itself.
        $html = str_replace(
            'src="/assets/brand/integrations-v10.js?v=6"',
            'src="/assets/brand/integrations-v10.js?v=7"',
            $html
        );
        // Production startup restores an older snapshot before runtime patches.
        // Normalize only the configured-rules page size after that restore.
        $rulesPaginationPatches = [
            '$rulesPage=min($rulesPage,max(1,(int)ceil($rulesTotal/5)));' =>
                '$rulesPage=min($rulesPage,max(1,(int)ceil($rulesTotal/6)));',
            'Repository::rules($rulesPage,5)' =>
                'Repository::rules($rulesPage,6)',
            "paginas(\$rulesPage,\$rulesTotal,'rules_page',5)" =>
                "paginas(\$rulesPage,\$rulesTotal,'rules_page',6)",
        ];
        foreach ($rulesPaginationPatches as $legacy => $target) {
            if (str_contains($html, $legacy)) {
                $html = str_replace($legacy, $target, $html);
            } elseif (!str_contains($html, $target)) {
                fwrite(STDERR, "BRAND_RULES_PAGINATION_ANCHOR_CHANGED\n");
                exit(1);
            }
        }
    }

    // Remove previous brand-specific head tags so each page has one source of truth.
    $html = preg_replace('~<link\b[^>]*rel=["\'](?:shortcut\s+)?icon["\'][^>]*>~i', '', $html) ?? $html;
    $html = preg_replace('~<link\b[^>]*rel=["\']manifest["\'][^>]*>~i', '', $html) ?? $html;
    $html = preg_replace('~<link\b[^>]*href=["\']/assets/brand/(?:brand|mobile-shell|integrations-v2|integrations-ui-v4|integrations-ui-v5|integrations-ui-v6|integrations-providers-v7|integrations-providers-v8|integrations-v9|integrations-v10)\.css[^"\']*["\'][^>]*>~i', '', $html) ?? $html;
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
