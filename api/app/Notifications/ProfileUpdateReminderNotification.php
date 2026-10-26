<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** INV-07 — the investor profile was not updated for `investor.profile_reminder_days` days. */
class ProfileUpdateReminderNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(private readonly int $days, private readonly ?Carbon $lastUpdatedAt)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'membership';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.profile_reminder.subject'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->line())
            ->line(__('notifications.profile_reminder.why'))
            ->action(__('notifications.profile_reminder.action'), $this->frontendUrl('/app/investor/profile'));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.profile_reminder.sms');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'investor.profile_reminder',
            'title' => __('notifications.profile_reminder.title'),
            'body' => $this->line(),
            'days' => $this->days,
            'last_updated_at' => $this->lastUpdatedAt?->toIso8601String(),
            'url' => '/app/investor/profile',
        ];
    }

    private function line(): string
    {
        return __('notifications.profile_reminder.line', ['days' => $this->days, 'date' => $this->lastUpdatedAt?->toDateString() ?? '—']);
    }
}
