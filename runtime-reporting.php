<?php declare(strict_types=1);

// TMR_REPORTING_CLEAN_V1
$root = __DIR__;
$smartPath = $root . '/app/SmartFormatting.php';
$routerPath = $root . '/app/TelegramRouter.php';
$indexPath = $root . '/index.php';

$removeTree = static function(string $dir) use (&$removeTree): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path) && !is_link($path)) {
            $removeTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
};

// Remove every legacy result implementation before touching the new runtime.
@unlink($root . '/results.php');
$removeTree($root . '/app/Results');
foreach (glob($root . '/scripts/result-*.php') ?: [] as $legacyScript) {
    @unlink($legacyScript);
}

foreach ([$smartPath, $routerPath, $indexPath] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, 'TMR_REPORTING_REQUIRED_FILE_MISSING ' . basename($required) . PHP_EOL);
        exit(1);
    }
}

$smart = file_get_contents($smartPath);
$router = file_get_contents($routerPath);
$index = file_get_contents($indexPath);
if (!is_string($smart) || !is_string($router) || !is_string($index)) {
    fwrite(STDERR, "TMR_REPORTING_READ_FAILED\n");
    exit(1);
}

$replaceOnce = static function(string $source, string $old, string $new, string $label): string {
    $count = substr_count($source, $old);
    if ($count !== 1) {
        throw new RuntimeException('TMR_REPORTING_ANCHOR_' . $label . '_COUNT_' . $count);
    }
    return str_replace($old, $new, $source);
};

try {
    // Remove the previous experimental tracking hook/state completely.
    $smart = preg_replace('/^\s*private static \?array \$lastStructuredBet=null;\s*$/m', '', $smart) ?? $smart;
    $smart = preg_replace('/^\s*self::\$lastStructuredBet\s*=\s*[^;]+;\s*$/m', '', $smart) ?? $smart;
    $smart = preg_replace('/^\s*public static function lastStructuredBet\(\): \?array\s*\{[^}]*\}\s*$/m', '', $smart) ?? $smart;
    $router = preg_replace('/^\s*try\s*\{\s*\\\\App\\\\Results\\\\TrackingBridge::afterForward\([^;]+;[^}]*\}\s*catch\([^}]+\}\s*$/m', '', $router) ?? $router;
    $router = preg_replace('/^\s*.*App\\\\Results\\\\TrackingBridge::afterForward.*$/m', '', $router) ?? $router;

    if (!str_contains($smart, 'private static ?array $lastReportingTicket=null;')) {
        $smart = $replaceOnce(
            $smart,
            '    private static bool $multipleDetailsTranslated=false;',
            "    private static bool \$multipleDetailsTranslated=false;\n    private static ?array \$lastReportingTicket=null;",
            'SMART_PROPERTY'
        );
    }

    if (!str_contains($smart, 'TMR_REPORTING_RESET_IN_PREPARE')) {
        $smart = $replaceOnce(
            $smart,
            '        self::$multipleDetailsTranslated=false;',
            "        self::\$multipleDetailsTranslated=false;\n        self::\$lastReportingTicket=null; // TMR_REPORTING_RESET_IN_PREPARE",
            'SMART_RESET'
        );
    }

    if (!str_contains($smart, 'public static function lastReportingTicket(): ?array')) {
        $anchor = '    public static function asText(array $bet,bool $translated): string';
        $methods = <<<'PHP'

    public static function resetReportingTicket(): void
    {
        self::$lastReportingTicket=null;
    }

    public static function lastReportingTicket(): ?array
    {
        return self::$lastReportingTicket;
    }

PHP;
        $smart = $replaceOnce($smart, $anchor, $methods . $anchor, 'SMART_GETTER');
    }

    if (!str_contains($smart, 'TMR_REPORTING_MODEL_ADAPTIVE')) {
        $anchor = "                                    self::diag('ADAPTIVE_CARD_READY_'.strtoupper((string)\$adaptive['kind']));";
        $smart = $replaceOnce(
            $smart,
            $anchor,
            $anchor . "\n                                    self::\$lastReportingTicket=\$adaptive; // TMR_REPORTING_MODEL_ADAPTIVE",
            'ADAPTIVE_MODEL'
        );
    }

    if (!str_contains($smart, 'TMR_REPORTING_MODEL_DOUBLE')) {
        $anchor = "                                self::diag('DOUBLE_CARD_READY');";
        if (str_contains($smart, $anchor)) {
            $doubleModel = <<<'PHP'
                                self::$lastReportingTicket=[
                                    'kind'=>'double',
                                    'bookmaker'=>'',
                                    'odd'=>(string)($double['odd']??''),
                                    'legs'=>array_map(static fn(array $leg): array=>[
                                        'sport'=>(string)($double['sport']??''),
                                        'match'=>(string)($leg['match']??''),
                                        'league'=>'',
                                        'date'=>'',
                                        'market'=>(string)($leg['market']??''),
                                        'selection'=>(string)($leg['selection']??''),
                                        'odd'=>(string)($leg['odd']??'')
                                    ],(array)($double['legs']??[]))
                                ]; // TMR_REPORTING_MODEL_DOUBLE
PHP;
            $smart = $replaceOnce($smart, $anchor, $anchor . "\n" . $doubleModel, 'DOUBLE_MODEL');
        }
    }

    if (!str_contains($smart, 'TMR_REPORTING_MODEL_LEGACY_SIMPLE')) {
        $anchor = '                $bet=self::sentenceCaseBet($candidate);';
        if (str_contains($smart, $anchor)) {
            $legacyModel = <<<'PHP'
                self::$lastReportingTicket=[
                    'kind'=>'simple',
                    'bookmaker'=>(string)($candidate['bookmaker']??''),
                    'odd'=>(string)($candidate['odd']??''),
                    'legs'=>[[
                        'sport'=>(string)($candidate['sport']??''),
                        'match'=>(string)($candidate['match']??''),
                        'league'=>(string)($candidate['league']??''),
                        'date'=>(string)($candidate['day']??''),
                        'market'=>(string)($candidate['market']??''),
                        'selection'=>(string)($candidate['selection']??''),
                        'odd'=>(string)($candidate['odd']??'')
                    ]]
                ]; // TMR_REPORTING_MODEL_LEGACY_SIMPLE
PHP;
            $smart = $replaceOnce($smart, $anchor, $anchor . "\n" . $legacyModel, 'LEGACY_SIMPLE_MODEL');
        }
    }

    if (!str_contains($router, 'private bool $reportingOutboxTimerStarted=false;')) {
        $router = $replaceOnce(
            $router,
            "    private array \$deliveryTelemetry=[];",
            "    private array \$deliveryTelemetry=[];\n    private bool \$reportingOutboxTimerStarted=false;",
            'ROUTER_PROPERTY'
        );
    }

    if (!str_contains($router, 'TMR_REPORTING_TIMER_V1')) {
        $anchor = '$pdo->prepare("INSERT INTO worker_status(worker_key,status,last_activity_at) VALUES(\'telegram-global\',\'conectado\',NOW()) ON DUPLICATE KEY UPDATE status=\'conectado\',last_activity_at=NOW(),last_error=NULL")->execute();';
        $timer = <<<'PHP'

        if(!$this->reportingOutboxTimerStarted){
            $this->reportingOutboxTimerStarted=true;
            \Revolt\EventLoop::repeat(15.0,function(): void {
                $this->flushReportingOutbox();
            });
        } // TMR_REPORTING_TIMER_V1
PHP;
        $router = $replaceOnce($router, '        ' . $anchor, '        ' . $anchor . $timer, 'ROUTER_TIMER');
    }

    if (!str_contains($router, 'TMR_REPORTING_RESET_EVENT_V1')) {
        $anchor = '        $id=(int)$message->id;';
        $router = $replaceOnce(
            $router,
            $anchor,
            $anchor . "\n        SmartFormatting::resetReportingTicket(); // TMR_REPORTING_RESET_EVENT_V1",
            'ROUTER_EVENT_RESET'
        );
    }

    if (!str_contains($router, 'TMR_REPORTING_CAPTURE_V1')) {
        $anchor = "            \$this->finish(\$source,\$id,'forwarded',\$details);";
        $hook = <<<'PHP'

            try {
                \App\Reporting\ReportingBridge::afterForward($rule,$source,$id);
            } catch(\Throwable $reportingError) {
                error_log('TMR_REPORTING_CAPTURE_HOOK_NON_FATAL '.get_class($reportingError));
            } // TMR_REPORTING_CAPTURE_V1
PHP;
        $router = $replaceOnce($router, $anchor, $anchor . $hook, 'ROUTER_CAPTURE');
    }

    if (!str_contains($router, 'private function flushReportingOutbox(): void')) {
        $anchor = '    private function finish(string $source,int $id,string $status,string $details): void';
        $methods = <<<'PHP'
    private function flushReportingOutbox(): void
    {
        $row=null;
        try {
            $settings=\App\Reporting\Repository::settings();
            if(empty($settings['enabled']) || empty($settings['daily_report']))return;
            $row=\App\Reporting\Repository::claimOutbox();
            if(!is_array($row))return;

            $response=$this->messages->sendMessage(
                peer:(string)$row['destination_chat'],
                message:(string)$row['payload'],
                entities:[],
                random_id:(int)$row['telegram_random_id']
            );
            \App\Reporting\Repository::markOutboxSent(
                (int)$row['id'],
                self::reportingTelegramMessageId($response)
            );
            error_log('TMR_REPORTING_OUTBOX_SENT id='.(int)$row['id']);
        } catch(\Throwable $e) {
            if(is_array($row) && isset($row['id'])){
                try {
                    \App\Reporting\Repository::markOutboxFailed((int)$row['id'],get_class($e).': '.$e->getMessage());
                } catch(\Throwable) {}
            }
            error_log('TMR_REPORTING_OUTBOX_NON_FATAL '.get_class($e));
        }
    }

    private static function reportingTelegramMessageId(mixed $response): ?int
    {
        if(is_object($response) && isset($response->id) && is_numeric($response->id)){
            return (int)$response->id;
        }
        if(is_array($response) && isset($response['id']) && is_numeric($response['id'])){
            return (int)$response['id'];
        }
        return null;
    }

PHP;
        $router = $replaceOnce($router, $anchor, $methods . $anchor, 'ROUTER_OUTBOX');
    }

    // Canonical navigation: remove the obsolete route and add only /reports.php.
    $index = preg_replace('~<a\b[^>]*href=["\']/results\.php["\'][^>]*>.*?</a>~is', '', $index) ?? $index;
    if (!str_contains($index, 'href="/reports.php"')) {
        $navAnchor = '</nav><div class="saas-bottom">';
        if (substr_count($index, $navAnchor) === 1) {
            $reportLink = '<a href="/reports.php"><span class="nav-icon">◎</span>Relatórios</a>';
            $index = str_replace($navAnchor, $reportLink . $navAnchor, $index);
        }
    }
    if (substr_count($index, 'href="/reports.php"') < 2) {
        $mobileAnchor = '<div class="tmr-more-panel">';
        if (substr_count($index, $mobileAnchor) === 1) {
            $mobileReportLink = '<a href="/reports.php"><span class="tmr-nav-icon" aria-hidden="true">◎</span><span>Relatórios</span></a>';
            $index = str_replace($mobileAnchor, $mobileAnchor . "\n      " . $mobileReportLink, $index);
        }
    }

    $candidates = [
        $smartPath . '.reporting-candidate' => $smart,
        $routerPath . '.reporting-candidate' => $router,
        $indexPath . '.reporting-candidate' => $index,
    ];

    foreach ($candidates as $path => $source) {
        if (file_put_contents($path, $source) === false) {
            throw new RuntimeException('TMR_REPORTING_CANDIDATE_WRITE_FAILED');
        }
        if (str_ends_with($path, '.php.reporting-candidate') || str_contains($path, '.php.')) {
            $output = [];
            $code = 0;
            exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
            if ($code !== 0) {
                throw new RuntimeException('TMR_REPORTING_CANDIDATE_LINT_FAILED ' . basename($path));
            }
        }
    }

    rename($smartPath . '.reporting-candidate', $smartPath);
    rename($routerPath . '.reporting-candidate', $routerPath);
    rename($indexPath . '.reporting-candidate', $indexPath);

    foreach ([
        $root . '/app/Reporting/Schema.php',
        $root . '/app/Reporting/TicketNormalizer.php',
        $root . '/app/Reporting/SettlementEngine.php',
        $root . '/app/Reporting/Repository.php',
        $root . '/app/Reporting/ReportingBridge.php',
        $root . '/app/Reporting/ApiFootballClient.php',
        $root . '/app/Reporting/FixtureMatcher.php',
        $root . '/app/Reporting/ResultWorker.php',
        $root . '/app/Reporting/DailyReportService.php',
        $root . '/scripts/report-worker.php',
        $root . '/reports.php',
    ] as $file) {
        if (!is_file($file)) {
            throw new RuntimeException('TMR_REPORTING_NEW_FILE_MISSING ' . basename($file));
        }
        $output = [];
        $code = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('TMR_REPORTING_NEW_FILE_LINT_FAILED ' . basename($file));
        }
    }

    echo "TMR_REPORTING_CLEAN_V1_READY\n";
} catch (Throwable $e) {
    @unlink($smartPath . '.reporting-candidate');
    @unlink($routerPath . '.reporting-candidate');
    @unlink($indexPath . '.reporting-candidate');
    fwrite(STDERR, 'TMR_REPORTING_INSTALL_FAILED ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
