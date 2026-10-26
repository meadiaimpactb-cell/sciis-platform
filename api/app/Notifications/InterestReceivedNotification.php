<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Interest;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** ENT-06 / MSG-01 — owner gets an access request WITHOUT the investor's identity. */
class InterestReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(private readonly Interest $interest)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'interest';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $opportunity = $this->interest->opportunity;

        return (new MailMessage)
            ->subject(__('notifications.interest_received.subject', ['ref' => $opportunity->reference_no ?? $opportunity->name]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.interest_received.line', ['name' => $opportunity->name]))
            ->line(__('notifications.interest_received.privacy'))
            ->action(__('notifications.interest_received.action'), $this->frontendUrl('/app/entrepreneur/access-requests'));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.interest_received.sms', ['ref' => $this->interest->opportunity->reference_no ?? '']);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'interest.received',
            'title' => __('notifications.interest_received.title'),
            'body' => __('notifications.interest_received.line', ['name' => $this->interest->opportunity->name]),
            'interest_id' => $this->interest->id,
            'opportunity_uuid' => $this->interest->opportunity->uuid,
            'due_at' => $this->interest->owner_decision_due_at?->toIso8601String(),
        ];
    }
}
