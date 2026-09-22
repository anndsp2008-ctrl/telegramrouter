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

$resetIcon = '<svg class="tmr-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v6h6"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>';

ob_start(static function (string $html) use ($script, $resetIcon): string {
    if ($script === 'index.php') {
        $html = str_replace(
            [
                'protected_media_reupload',
                'direct_forward',
                'direct_send',
                'smart_original_fallback',
                'ai_vip_card_contingency_partial',
                'ai_vip_card_contingency',
                'ai_vip_card_partial',
                'ai_vip_card',
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
                'Original preservado após falha da IA',
                'Card VIP de contingência; continuação parcial',
                'Card VIP de contingência',
                'Card VIP enviado; continuação parcial',
                'Card VIP gerado por IA',
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

        // The approved mobile "Mais" menu also links to /reset.php. Check
        // only the desktop sidebar: a whole-document search suppresses its
        // Reset link whenever the mobile navigation is present.
        $html = preg_replace_callback(
            '~<nav class="saas-nav">.*?</nav>~s',
            static function (array $match) use ($resetIcon): string {
                if (str_contains($match[0], 'href="/reset.php"')) return $match[0];
                $resetLink = '<a href="/reset.php"><span class="nav-icon">'.$resetIcon.'</span>Reset de dados</a>';
                return str_replace('</nav>', $resetLink.'</nav>', $match[0]);
            },
            $html,
            1
        ) ?? $html;

        if (!str_contains($html, 'id="telegramrouter-sidebar-sync"')) {
            // Icon geometry must be available before first paint. Injecting this
            // stylesheet at the end of the body caused a momentary size change
            // during navigation, especially when opening Integrations.
            $sidebarStyle = <<<'HTML'
<style id="telegramrouter-sidebar-icon-compat">
.saas-sidebar .nav-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 22px;width:22px;height:22px;min-width:22px;min-height:22px;line-height:0}
.saas-sidebar .nav-icon .nav-icon-svg{display:block;width:20px;height:20px;min-width:20px;min-height:20px;stroke:currentColor}
</style>
HTML;
            $sidebarSync = <<<'HTML'
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
            $html = str_replace('</head>', $sidebarStyle.'</head>', $html);
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

    // Use the deployed main panel's menu as the ONLY icon, order and label
    // source. All dashboard/rules/activity/integrations routes share index.php.
    // Reset previously reconstructed different SVGs for the same links.
    // Read source markup (do not execute index.php or touch its application
    // state); strip its dynamic active classes so Reset alone is highlighted.
    $mainMarkup = @file_get_contents(__DIR__.'/index.php');
    $navPattern = '~<nav class="saas-nav">.*?</nav>~s';
    $linkPattern = '~\bhref="([^"]+)"[^>]*>(<span class="nav-icon">.*?</span>.*?)</a>~s';
    $expectedMenu = [
        '/?page=dashboard','/?page=rules','/ai-learning.php',
        '/?page=events','/?page=integrations','/connect.php'
    ];
    $mainNav = [];
    $resetNav = [];
    if (is_string($mainMarkup)
        && preg_match_all($navPattern,$mainMarkup,$mainNav)===1
        && preg_match_all($navPattern,$html,$resetNav)===1) {
        $items = [];
        preg_match_all($linkPattern,$mainNav[0][0],$items,PREG_SET_ORDER);
        $destinations = array_map(static fn(array $item): string=>$item[1],$items);
        $iconsValid = count($items)===count($expectedMenu);
        foreach ($items as $item) {
            if (substr_count($item[2],'class="nav-icon"')!==1
                || str_contains($item[2],'<?')) $iconsValid=false;
        }
        if ($iconsValid && $destinations===$expectedMenu) {
            $canonicalNav = '<nav class="saas-nav">';
            foreach ($items as $item) {
                // These hrefs were checked against the literal route whitelist.
                // Keep the deployed index's icon markup and labels unchanged.
                $canonicalNav .= '<a href="'.$item[1].'">'.$item[2].'</a>';
            }
            $canonicalNav .= '<a class="active" href="/reset.php">'
                .'<span class="nav-icon">'.$resetIcon.'</span>Reset de dados</a></nav>';
            $html = str_replace($resetNav[0][0],$canonicalNav,$html);
        } else {
            error_log('TMR_SIDEBAR_CANONICAL_MENU_UNEXPECTED_INDEX');
        }
    } else {
        error_log('TMR_SIDEBAR_CANONICAL_MENU_SOURCE_UNAVAILABLE');
    }

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
