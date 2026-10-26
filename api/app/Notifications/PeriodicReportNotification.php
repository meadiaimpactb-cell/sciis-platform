<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PeriodicReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Journey 11.10 — `awaiting` (to approvers, in-app + e-mail), `returned` (to the author, in-app + e-mail),
 * `published` (to staff, in-app only).
 */
class PeriodicReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly PeriodicReport $report, private readonly string $event)
    {
        $this->onQueue('notifications');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->event === 'published' ? ['database'] : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__("reporting.notifications.periodic.{$this->event}_subject", ['title' => $this->report->title]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->line())
            ->action(__('reporting.notifications.periodic.action'), rtrim((string) config('app.frontend_url'), '/').'/app/admin/reports?tab=periodic');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'periodic_report.'.$this->event,
            'title' => __("reporting.notifications.periodic.{$this->event}_title"),
            'body' => $this->line(),
            'periodic_report_uuid' => $this->report->uuid,
            'url' => '/app/admin/reports?tab=periodic',
        ];
    }

    private function line(): string
    {
        return __("reporting.notifications.periodic.{$this->event}_line", [
            'title' => $this->report->title,
            'note' => (string) $this->report->review_note,
            'audience' => __('reporting.periodic.audiences.'.$this->report->audience),
        ]);
    }
}
