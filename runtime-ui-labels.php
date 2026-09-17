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

ob_start(static function (string $html) use ($script): string {
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
            $resetLink = '<a href="/reset.php"><span class="nav-icon">↺</span>Reset de dados</a>';
            $html = preg_replace('/<\/nav>/', $resetLink.'</nav>', $html, 1) ?? $html;
        }

        if (!str_contains($html, 'id="telegramrouter-sidebar-sync"')) {
            $sidebarSync = <<<'HTML'
<script id="telegramrouter-sidebar-sync">
(()=>{
    try {
        const sidebar=document.querySelector('.saas-sidebar');
        if(!sidebar) return;
        sessionStorage.setItem('telegramrouter.sidebar.html',sidebar.outerHTML);
        sessionStorage.setItem('telegramrouter.sidebar.width',String(Math.round(sidebar.getBoundingClientRect().width)));
    } catch (_) {}
})();
</script>
HTML;
            $html = str_replace('</body>', $sidebarSync.'</body>', $html);
        }

        return $html;
    }

    // Reset module: reuse the already-rendered sidebar from the main panel.
    // This keeps width, patched icons, menu spacing and branding identical.
    if (!str_contains($html, 'id="telegramrouter-reset-sidebar-compat"')) {
        $compat = <<<'HTML'
<style id="telegramrouter-reset-sidebar-compat">
.saas-sidebar .nav-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 22px;width:22px;height:22px;min-width:22px;min-height:22px;line-height:1;font-size:18px}
.saas-sidebar .saas-nav a{box-sizing:border-box}
@media (min-width:981px){
  .saas-sidebar{width:var(--telegramrouter-sidebar-width,260px);min-width:var(--telegramrouter-sidebar-width,260px);max-width:var(--telegramrouter-sidebar-width,260px);flex:0 0 var(--telegramrouter-sidebar-width,260px);box-sizing:border-box}
  .saas-main{min-width:0}
}
</style>
<script id="telegramrouter-reset-sidebar-sync">
(()=>{
    try {
        const current=document.querySelector('.saas-sidebar');
        if(!current) return;

        const storedWidth=Number(sessionStorage.getItem('telegramrouter.sidebar.width')||0);
        if(Number.isFinite(storedWidth)&&storedWidth>=200&&storedWidth<=420){
            document.documentElement.style.setProperty('--telegramrouter-sidebar-width',storedWidth+'px');
        }

        const saved=sessionStorage.getItem('telegramrouter.sidebar.html');
        if(saved){
            const template=document.createElement('template');
            template.innerHTML=saved.trim();
            const restored=template.content.querySelector('.saas-sidebar');
            if(restored){
                const restoredNav=restored.querySelector('.saas-nav');
                let resetLink=restored.querySelector('a[href="/reset.php"]');
                if(!resetLink){
                    const localReset=current.querySelector('a[href="/reset.php"]');
                    if(localReset&&restoredNav){
                        resetLink=localReset.cloneNode(true);
                        restoredNav.appendChild(resetLink);
                    }
                }
                restored.querySelectorAll('.saas-nav a.active').forEach(link=>link.classList.remove('active'));
                if(resetLink) resetLink.classList.add('active');
                current.replaceWith(restored);
            }
        }

        const activeReset=document.querySelector('.saas-sidebar a[href="/reset.php"]');
        if(activeReset){
            document.querySelectorAll('.saas-sidebar .saas-nav a.active').forEach(link=>link.classList.remove('active'));
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
