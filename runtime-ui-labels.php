<?php declare(strict_types=1);

/*
 * UI-only formatter for operational event details and navigation.
 * Internal identifiers, database values and worker logs remain untouched.
 */
if (PHP_SAPI === 'cli') {
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

        return $html;
    }

    // Force a new stylesheet revision so browsers do not keep the previous compact layout.
    $html = str_replace('/assets/reset.css?v=2', '/assets/reset.css?v=3', $html);

    // The main runtime injects responsive.css into index/connect/install only.
    // Reset must load the same responsive layer to preserve sidebar geometry.
    if (!str_contains($html, '/assets/responsive.css?v=4')) {
        $html = str_replace(
            '<link rel="stylesheet" href="/assets/reset.css?v=3">',
            '<link rel="stylesheet" href="/assets/responsive.css?v=4"><link rel="stylesheet" href="/assets/reset.css?v=3">',
            $html
        );
    }

    if (!str_contains($html, 'id="telegramrouter-reset-sidebar-compat"')) {
        $compat = <<<'HTML'
<style id="telegramrouter-reset-sidebar-compat">
.saas-sidebar{flex-shrink:0;box-sizing:border-box}
.saas-sidebar .saas-nav a{box-sizing:border-box}
.saas-sidebar .nav-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 22px;width:22px;height:22px;min-width:22px;min-height:22px;line-height:0}
.saas-sidebar .nav-icon .nav-icon-svg{display:block;width:20px;height:20px;min-width:20px;min-height:20px;stroke:currentColor}
</style>
<script id="telegramrouter-reset-sidebar-sync">
(()=>{
    const svg=(body)=>'<svg class="nav-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+body+'</svg>';
    const fallbackIcons={
        '/?page=dashboard':svg('<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M9.5 20v-6h5v6"/>'),
        '/?page=rules':svg('<path d="M4 6h10"/><path d="M18 6h2"/><path d="M4 12h2"/><path d="M10 12h10"/><path d="M4 18h7"/><path d="M15 18h5"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="13" cy="18" r="2"/>'),
        '/?page=events':svg('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
        '/?page=integrations':svg('<path d="M8 12h8"/><path d="M12 8v8"/><path d="M7 3v4"/><path d="M17 3v4"/><path d="M7 17v4"/><path d="M17 17v4"/><path d="M5 7h14v10H5z"/>'),
        '/connect.php':svg('<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>'),
        '/reset.php':svg('<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v6h6"/><path d="M12 8v4"/><path d="M12 16h.01"/>')
    };

    try {
        let current=document.querySelector('.saas-sidebar');
        if(!current) return;

        const saved=sessionStorage.getItem('telegramrouter.sidebar.html');
        if(saved){
            const template=document.createElement('template');
            template.innerHTML=saved.trim();
            const restored=template.content.querySelector('.saas-sidebar');
            if(restored){
                const restoredNav=restored.querySelector('.saas-nav');
                let resetLink=restored.querySelector('a[href="/reset.php"]');
                if(!resetLink&&restoredNav){
                    const localReset=current.querySelector('a[href="/reset.php"]');
                    if(localReset){
                        resetLink=localReset.cloneNode(true);
                        restoredNav.appendChild(resetLink);
                    }
                }
                restored.querySelectorAll('.saas-nav a.active').forEach(link=>link.classList.remove('active'));
                if(resetLink) resetLink.classList.add('active');
                current.replaceWith(restored);
                current=restored;
            }
        }

        const storedWidth=Number(sessionStorage.getItem('telegramrouter.sidebar.width')||0);
        if(Number.isFinite(storedWidth)&&storedWidth>=180&&storedWidth<=480){
            current.style.width=storedWidth+'px';
            current.style.minWidth=storedWidth+'px';
            current.style.maxWidth=storedWidth+'px';
            current.style.flexBasis=storedWidth+'px';
        }

        current.querySelectorAll('.saas-nav a').forEach(link=>{
            const href=link.getAttribute('href')||'';
            const icon=link.querySelector('.nav-icon');
            if(icon&&fallbackIcons[href]&&!icon.querySelector('svg')) icon.innerHTML=fallbackIcons[href];
        });

        const activeReset=current.querySelector('a[href="/reset.php"]');
        if(activeReset){
            current.querySelectorAll('.saas-nav a.active').forEach(link=>link.classList.remove('active'));
            activeReset.classList.add('active');
        }
    } catch (_) {}
})();
</script>
HTML;
        $html = str_replace('</body>', $compat.'</body>', $html);
    }

    return $html;
});
