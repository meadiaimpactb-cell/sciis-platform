<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SavedReport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * RPT-05 — a scheduled custom report e-mailed to a staff recipient with the xlsx / PDF attached (+ in-app entry).
 * Sent synchronously by `sciis:scheduled-reports` (the attachment is not pushed through the queue payload).
 */
class ScheduledReportNotification extends Notification
{
    public function __construct(
        private readonly SavedReport $report,
        private readonly string $content,
        private readonly string $filename,
        private readonly string $mime,
        private readonly int $rows,
        private readonly int $total,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('reporting.notifications.scheduled.subject', ['name' => $this->report->name]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->line());

        if ($this->total > $this->rows) {
            $mail->line(__('reporting.notifications.scheduled.truncated', ['shown' => $this->rows, 'total' => $this->total]));
        }

        return $mail->attachData($this->content, $this->filename, ['mime' => $this->mime]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report.scheduled',
            'title' => __('reporting.notifications.scheduled.title'),
            'body' => $this->line(),
            'saved_report_uuid' => $this->report->uuid,
            'url' => '/app/admin/reports?tab=saved',
        ];
    }

    private function line(): string
    {
        return __('reporting.notifications.scheduled.line', ['name' => $this->report->name, 'rows' => $this->rows, 'date' => now()->setTimezone('Asia/Riyadh')->toDateString()]);
    }
}
