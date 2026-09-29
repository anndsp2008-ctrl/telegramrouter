<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/Database.php';

use App\Database;
use App\Reporting\DailyReportService;
use App\Reporting\Repository;
use App\Reporting\ResultWorker;
use App\Reporting\Schema;

Schema::migrate();

$pdo = Database::pdo();
$lock = (int)$pdo->query("SELECT GET_LOCK('telegramrouter_reporting_worker_v1',0)")->fetchColumn();
if ($lock !== 1) {
    error_log('TMR_REPORTING_WORKER_ALREADY_RUNNING');
    exit(0);
}

register_shutdown_function(static function (): void {
    try {
        Database::pdo()->query("SELECT RELEASE_LOCK('telegramrouter_reporting_worker_v1')");
    } catch (Throwable) {
    }
});

$lastSettlement = 0;
Repository::setState('scheduler_status', 'running');
Repository::setState('scheduler_started_at', gmdate('Y-m-d H:i:s'));

while (true) {
    $now = time();

    $manualPendingCheck = Repository::consumeManualPendingCheckRequest();

    if (($now - $lastSettlement) >= 300 || $manualPendingCheck) {
        try {
            ResultWorker::runOnce();
            if ($manualPendingCheck) {
                Repository::setState('manual_pending_check_status', 'done');
                Repository::setState('manual_pending_check_completed_at', gmdate('Y-m-d H:i:s'));
                error_log('TMR_REPORTING_MANUAL_PENDING_DONE');
            }
        } catch (Throwable $e) {
            Repository::setState('worker_status', 'error');
            Repository::setState('worker_last_error', get_class($e));
            if ($manualPendingCheck) {
                Repository::setState('manual_pending_check_status', 'error');
            }
            error_log('TMR_REPORTING_WORKER_NON_FATAL ' . get_class($e));
        }
        $lastSettlement = $now;
    }

    try {
        DailyReportService::enqueueIfDue();
    } catch (Throwable $e) {
        Repository::setState('daily_report_status', 'error');
        Repository::setState('daily_report_last_error', get_class($e));
        error_log('TMR_REPORTING_DAILY_NON_FATAL ' . get_class($e));
    }

    Repository::setState('scheduler_heartbeat_at', gmdate('Y-m-d H:i:s'));
    sleep(20);
}
