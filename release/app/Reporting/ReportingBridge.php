<?php declare(strict_types=1);

namespace App\Reporting;

final class ReportingBridge
{
    public static function afterForward(array $rule, string $sourceChat, int $messageId): void
    {
        try {
            if (!Repository::ruleEnabled((int)($rule['id'] ?? 0))) {
                return;
            }
            if (!method_exists(\App\SmartFormatting::class, 'lastReportingTicket')) {
                return;
            }

            $model = \App\SmartFormatting::lastReportingTicket();
            if (!is_array($model)) {
                return;
            }

            $ticket = TicketNormalizer::fromModel($model);
            if ($ticket === null) {
                error_log('TMR_REPORTING_CAPTURE_SKIPPED INVALID_MODEL');
                return;
            }

            Repository::captureTicket($ticket, [
                'rule_id' => (int)($rule['id'] ?? 0),
                'source_chat' => $sourceChat,
                'destination_chat' => (string)($rule['destination_chat'] ?? ''),
                'message_id' => $messageId,
            ]);

            error_log('TMR_REPORTING_CAPTURED ' . json_encode([
                'rule_id' => (int)($rule['id'] ?? 0),
                'kind' => (string)($ticket['kind'] ?? 'simple'),
                'legs' => count((array)($ticket['legs'] ?? [])),
            ], JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            error_log('TMR_REPORTING_CAPTURE_NON_FATAL ' . get_class($e));
        }
    }
}
