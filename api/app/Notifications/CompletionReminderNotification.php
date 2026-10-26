<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CompletionRequest;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** Journey 11.3 — owner reminder before / on the completion due date, with the auto-close date when it applies. */
class CompletionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(private readonly CompletionRequest $request, private readonly ?Carbon $autoCloseOn = null)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'opportunity';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $opportunity = $this->request->opportunity;
        $mail = (new MailMessage)
            ->subject(__('notifications.completion_reminder.subject', ['ref' => $opportunity->reference_no ?? $opportunity->name]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->line());

        if ($this->autoCloseOn !== null) {
            $mail->line(__('notifications.completion_reminder.auto_close', ['date' => $this->autoCloseOn->toDateString()]));
        }

        return $mail->action(__('notifications.completion_reminder.action'), $this->frontendUrl('/app/entrepreneur/opportunities/'.$opportunity->uuid.'/edit'));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.completion_reminder.sms', [
            'ref' => $this->request->opportunity->reference_no ?? '',
            'date' => $this->request->due_at?->toDateString() ?? '',
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'opportunity.completion_reminder',
            'title' => __('notifications.completion_reminder.title'),
            'body' => $this->line(),
            'opportunity_uuid' => $this->request->opportunity->uuid,
            'completion_request_id' => $this->request->id,
            'due_at' => $this->request->due_at?->toIso8601String(),
            'auto_close_on' => $this->autoCloseOn?->toDateString(),
        ];
    }

    private function line(): string
    {
        $due = $this->request->due_at;
        $key = $due !== null && $due->isToday() ? 'line_today' : 'line_upcoming';

        return __("notifications.completion_reminder.{$key}", ['name' => $this->request->opportunity->name, 'date' => $due?->toDateString() ?? '']);
    }
}
