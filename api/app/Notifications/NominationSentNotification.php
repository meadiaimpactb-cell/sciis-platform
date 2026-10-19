<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Nomination;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** MAT-01 — investor receives a nomination (teaser + rationale). */
class NominationSentNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(private readonly Nomination $nomination)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'interest';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $opportunity = $this->nomination->opportunity;

        return (new MailMessage)
            ->subject(__('notifications.nomination.subject'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.nomination.line', ['name' => $opportunity->name]))
            ->line(__('notifications.nomination.rationale', ['rationale' => (string) $this->nomination->rationale]))
            ->line(__('notifications.nomination.score', ['score' => $this->nomination->match_score, 'criteria' => implode('، ', array_map(fn (string $c): string => __('enums.match_criteria.'.$c), (array) $this->nomination->matched_criteria))]))
            ->action(__('notifications.nomination.action'), $this->frontendUrl('/investor/nominations'));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.nomination.sms');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'nomination.sent',
            'title' => __('notifications.nomination.title'),
            'body' => __('notifications.nomination.line', ['name' => $this->nomination->opportunity->name]),
            'nomination_id' => $this->nomination->id,
            'opportunity_uuid' => $this->nomination->opportunity->uuid,
            'match_score' => $this->nomination->match_score,
            'matched_criteria' => $this->nomination->matched_criteria,
        ];
    }
}
