<?php declare(strict_types=1);

namespace App\Reporting;

final class DailyReportService
{
    public static function enqueueIfDue(): void
    {
        $settings = Repository::settings();
        if (empty($settings['enabled']) || empty($settings['daily_report'])) {
            return;
        }

        $timezoneName = trim((string)($settings['timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo';
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable) {
            $timezoneName = 'America/Sao_Paulo';
            $timezone = new \DateTimeZone($timezoneName);
        }

        $now = new \DateTimeImmutable('now', $timezone);
        $reportTime = preg_match('/^\d{2}:\d{2}/', (string)($settings['report_time'] ?? ''), $m)
            ? $m[0]
            : '00:00';
        $due = new \DateTimeImmutable($now->format('Y-m-d') . ' ' . $reportTime . ':00', $timezone);
        if ($now < $due) {
            return;
        }

        $date = $now->modify('-1 day')->format('Y-m-d');
        $forcedChat = trim((string)($settings['report_chat'] ?? ''));

        if ($forcedChat !== '') {
            $stats = Repository::reportStats($date, $timezoneName, null);
            $stats['cumulative_profit'] = Repository::cumulativeProfit($date, $timezoneName, null);
            Repository::enqueueDailyReport($date, $forcedChat, self::format($date, $stats));
            Repository::setState('daily_report_last_check_at', gmdate('Y-m-d H:i:s'));
            return;
        }

        foreach (Repository::destinationsForDate($date, $timezoneName) as $destination) {
            $stats = Repository::reportStats($date, $timezoneName, $destination);
            if ((int)($stats['total'] ?? 0) <= 0) {
                continue;
            }
            Repository::enqueueDailyReport($date, $destination, self::format($date, $stats));
        }

        Repository::setState('daily_report_last_check_at', gmdate('Y-m-d H:i:s'));
    }

    public static function format(string $date, array $stats): string
    {
        $total = (int)($stats['total'] ?? 0);
        $greens = (int)($stats['greens'] ?? 0);
        $reds = (int)($stats['reds'] ?? 0);
        $voids = (int)($stats['voids'] ?? 0);
        $halfGreens = (int)($stats['half_greens'] ?? 0);
        $halfReds = (int)($stats['half_reds'] ?? 0);
        $pending = (int)($stats['pending'] ?? 0);
        $review = (int)($stats['review'] ?? 0);
        $profit = (float)($stats['profit'] ?? 0);
        $avgOdds = (float)($stats['avg_odds'] ?? 0);
        $settledStake = (float)($stats['settled_stake'] ?? 0);
        $cumulativeProfit = (float)($stats['cumulative_profit'] ?? 0);

        $decisions = $greens + $reds + $halfGreens + $halfReds;
        $weightedWins = $greens + (0.5 * $halfGreens);
        $hitRate = $decisions > 0 ? ($weightedWins / $decisions) * 100 : 0.0;
        $roi = $settledStake > 0 ? ($profit / $settledStake) * 100 : 0.0;

        try {
            $displayDate = (new \DateTimeImmutable($date))->format('d/m/Y');
        } catch (\Throwable) {
            $displayDate = $date;
        }

        $resultSign = $profit > 0 ? '+' : '';
        $cumulativeSign = $cumulativeProfit > 0 ? '+' : '';
        $roiSign = $roi > 0 ? '+' : '';

        return
            "📊 RELATÓRIO DIÁRIO — {$displayDate}\n\n" .
            "Apostas: {$total}\n" .
            "🟢 Greens: {$greens}\n" .
            "🔴 Reds: {$reds}\n" .
            "🟡 Half Green: {$halfGreens}\n" .
            "🟠 Half Red: {$halfReds}\n" .
            "⚪ Void: {$voids}\n" .
            "⏳ Pendentes: {$pending}\n" .
            "🔎 Revisão: {$review}\n\n" .
            "Taxa de acerto: " . number_format($hitRate, 2, ',', '.') . "%\n" .
            "Stake padrão: 10 unidades\n" .
            "Volume liquidado: " . number_format($settledStake, 2, ',', '.') . " unidades\n\n" .
            "💰 Resultado do dia: {$resultSign}" . number_format($profit, 2, ',', '.') . " unidades\n" .
            "🏦 Acumulado geral: {$cumulativeSign}" . number_format($cumulativeProfit, 2, ',', '.') . " unidades\n" .
            "📈 ROI do dia: {$roiSign}" . number_format($roi, 2, ',', '.') . "%\n" .
            "Odd média: " . number_format($avgOdds, 2, '.', '');
    }
}
