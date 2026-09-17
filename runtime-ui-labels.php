<?php declare(strict_types=1);

/*
 * UI-only formatter for operational event details.
 * Internal identifiers, database values and worker logs remain untouched.
 */
if (PHP_SAPI === 'cli') {
    return;
}

$script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
if ($script !== 'index.php') {
    return;
}

ob_start(static function (string $html): string {
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

    return $html;
});
