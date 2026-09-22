<?php declare(strict_types=1);

/*
 * UI-only formatter for operational event details and navigation.
 * Internal identifiers, database values and worker logs remain untouched.
 */
if (PHP_SAPI === 'cli' && getenv('TMR_NAV_TEST_ONLY') !== '1') {
    return;
}

$script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (!in_array($script, ['index.php', 'reset.php'], true)) {
    return;
}

$resetIcon = '<svg class="nav-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v6h6"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>';

ob_start(static function (string $html) use ($script, $resetIcon): string {
    if ($script === 'index.php') {
        $html = str_replace(
            [
                'protected_media_reupload',
                'direct_forward',
                'direct_send',
                'Cleanup: ok',
                'Cleanup: failed',
                'Download:',
                'Reupload:',
                'Envio Telegram:',
                'Processamento total:',
            ],
            [
                'Reenvio de mídia protegida',
                'Envio direto',
                'Envio direto',
                'Arquivo temporário: removido com sucesso',
                'Arquivo temporário: falha na remoção',
                'Download da mídia:',
                'Reenvio da mídia:',
                'Envio ao Telegram:',
                'Tempo total:',
            ],
            $html
        );

        $html = preg_replace_callback(
            '/Mídia temporária:\s*(\d+)\s*bytes/iu',
            static function (array $match): string {
                $bytes = (int)$match[1];
                if ($bytes < 1024) {
                    return 'Mídia temporária: '.$bytes.' B';
                }
                if ($bytes < 1048576) {
                    return 'Mídia temporária: '.number_format($bytes / 1024, 1, ',', '.').' KB';
                }
                return 'Mídia temporária: '.number_format($bytes / 1048576, 1, ',', '.').' MB';
            },
            $html
        ) ?? $html;

        if (!str_contains($html, 'href="/reset.php"')) {
            $resetLink = '<a href="/reset.php"><span class="nav-icon">'.$resetIcon.'</span>Reset de dados</a>';
            $html = preg_replace('/<\/nav>/', $resetLink.'</nav>', $html, 1) ?? $html;
        }

        if (!str_contains($html, 'id="telegramrouter-sidebar-sync"')) {
            $sidebarSync = <<<'HTML'
<style id="telegramrouter-sidebar-icon-compat">
.saas-sidebar .nav-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 22px;width:22px;height:22px;min-width:22px;min-height:22px;line-height:0}
.saas-sidebar .nav-icon .nav-icon-svg{display:block;width:20px;height:20px;min-width:20px;min-height:20px;stroke:currentColor}
</style>
<script id="telegramrouter-sidebar-sync">
(()=>{
    try {
        const sidebar=document.querySelector('.saas-sidebar');
        if(!sidebar) return;
        sessionStorage.setItem('telegramrouter.sidebar.html',sidebar.outerHTML);
        const width=Math.round(sidebar.getBoundingClientRect().width);
        if(width>=180&&width<=480) sessionStorage.setItem('telegramrouter.sidebar.width',String(width));
    } catch (_) {}
})();
</script>
HTML;
            $html = str_replace('</body>', $sidebarSync.'</body>', $html);
        }

        // An unrelated page-level notification may mistakenly mark one of the
        // application containers as feedback when the Integrations page loads.
        // Shield only the existing navigation/app shell; leave provider cards,
        // test messages and Integrations behavior unchanged.
        if (str_contains($html, 'class="translation-provider-grid"')
            && !str_contains($html, 'id="tmr-sidebar-feedback-guard"')) {
            $sidebarStyle = <<<'HTML'
<style id="tmr-sidebar-feedback-style">
.saas-shell.integrations-global-test-feedback{display:flex!important}
.saas-sidebar.integrations-global-test-feedback{display:flex!important}
.saas-main.integrations-global-test-feedback{display:block!important}
.saas-content.integrations-global-test-feedback{display:block!important}
</style>
HTML;
            $sidebarGuard = <<<'HTML'
<script id="tmr-sidebar-feedback-guard">
(()=>{
    const containers=['.saas-shell','.saas-sidebar','.saas-main','.saas-content']
        .map(selector=>document.querySelector(selector)).filter(Boolean);
    const marker='integrations-global-test-feedback';
    const restore=element=>{
        if(!element.classList.contains(marker)) return;
        element.classList.remove(marker);
        element.removeAttribute('hidden');
        if(element.getAttribute('aria-hidden')==='true'){
            element.removeAttribute('aria-hidden');
        }
    };
    if(typeof MutationObserver==='undefined') return;
    const observer=new MutationObserver(changes=>{
        for(const change of changes) restore(change.target);
    });
    for(const element of containers){
        observer.observe(element,{
            attributes:true,
            attributeFilter:['class','hidden','aria-hidden']
        });
        restore(element);
    }
})();
</script>
HTML;
            // CSS is available before paint; the observer is registered before
            // deferred scripts execute. No navigation or page body is replaced.
            $html = str_replace('</head>', $sidebarStyle.'</head>', $html);
            $html = str_replace('</body>', $sidebarGuard.'</body>', $html);
        }

        return $html;
    }

    // Always normalize the Reset stylesheet URL to the current revision.
    $html = str_replace(
        ['/assets/reset.css?v=2', '/assets/reset.css?v=3', '/assets/reset.css?v=4', '/assets/reset.css?v=5'],
        '/assets/reset.css?v=6',
        $html
    );

    // The main runtime injects responsive.css into index/connect/install only.
    // Reset must load the same responsive layer to preserve sidebar geometry.
    if (!str_contains($html, '/assets/responsive.css?v=4')) {
        $html = str_replace(
            '<link rel="stylesheet" href="/assets/reset.css?v=6">',
            '<link rel="stylesheet" href="/assets/responsive.css?v=4"><link rel="stylesheet" href="/assets/reset.css?v=6">',
            $html
        );
    }

    // Render the canonical menu before sending the page to the browser.
    // Previously Reset displayed its old menu and later replaced the whole
    // sidebar from sessionStorage, causing a visible flash/reflow.
    $navPattern = '~<nav class="saas-nav">.*?</nav>~s';
    $linkPattern = '~<a\b[^>]*\bhref="([^"]+)"[^>]*>.*?</a>~s';
    $ruleLinkPattern = '~<a\b[^>]*\bhref="/\?page=rules"[^>]*>.*?</a>~s';
    $learnLinkPattern = '~<a\b[^>]*\bhref="/ai-learning\.php"[^>]*>.*?</a>~s';
    $svg = static fn(string $paths): string => '<svg class="nav-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>';
    $menuIcons = [
        '/?page=dashboard' => $svg('<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M9.5 20v-6h5v6"/>'),
        '/?page=rules' => $svg('<path d="M4 6h10"/><path d="M18 6h2"/><path d="M4 12h2"/><path d="M10 12h10"/><path d="M4 18h7"/><path d="M15 18h5"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="13" cy="18" r="2"/>'),
        '/ai-learning.php' => $svg('<path d="m12 2 2.2 6.8L21 11l-6.8 2.2L12 20l-2.2-6.8L3 11l6.8-2.2Z"/>'),
        '/?page=events' => $svg('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
        '/?page=integrations' => $svg('<path d="M8 12h8"/><path d="M12 8v8"/><path d="M7 3v4"/><path d="M17 3v4"/><path d="M7 17v4"/><path d="M17 17v4"/><path d="M5 7h14v10H5z"/>'),
        '/connect.php' => $svg('<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>'),
        '/reset.php' => $resetIcon,
    ];
    $html = preg_replace_callback($navPattern,
        static function (array $match) use ($ruleLinkPattern, $learnLinkPattern, $linkPattern, $menuIcons): string {
            $nav = $match[0];
            $rulesCount = preg_match_all($ruleLinkPattern, $nav);
            $learningCount = preg_match_all($learnLinkPattern, $nav, $learnMatches);
            if ($rulesCount !== 1 || $learningCount === false || $learningCount > 1) {
                return $nav; // Unknown sidebar: preserve it untouched.
            }
            $learn = $learningCount === 1 ? $learnMatches[0][0]
                : '<a href="/ai-learning.php"><span class="nav-icon">✦</span>Aprendizado da IA</a>';
            if ($learningCount === 1) $nav = str_replace($learn, '', $nav);
            $nav = preg_replace_callback($ruleLinkPattern,
                static fn(array $rule): string => $rule[0].$learn, $nav, 1) ?? $nav;
            // Render icons on the server. Do not replace the sidebar (or its
            // icons) after first paint; this also preserves keyboard focus.
            return preg_replace_callback($linkPattern,
                static function (array $link) use ($menuIcons): string {
                    $icon = $menuIcons[$link[1]] ?? null;
                    if ($icon === null || str_contains($link[0], '<svg')) return $link[0];
                    return preg_replace('~<span class="nav-icon">.*?</span>~s',
                        '<span class="nav-icon">'.$icon.'</span>', $link[0], 1) ?? $link[0];
                }, $nav) ?? $nav;
        }, $html, 1) ?? $html;

    if (!str_contains($html, 'id="telegramrouter-reset-sidebar-compat"')) {
        $compat = <<<'HTML'
<style id="telegramrouter-reset-sidebar-compat">
.saas-sidebar{flex-shrink:0;box-sizing:border-box}
.saas-sidebar .saas-nav a{box-sizing:border-box}
.saas-sidebar .nav-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 22px;width:22px;height:22px;min-width:22px;min-height:22px;line-height:0}
.saas-sidebar .nav-icon .nav-icon-svg{display:block;width:20px;height:20px;min-width:20px;min-height:20px;stroke:currentColor}
</style>
HTML;
        // Style must be present before first paint, not appended after </body>.
        $html = str_replace('</head>', $compat.'</head>', $html);
    }

    return $html;
});
