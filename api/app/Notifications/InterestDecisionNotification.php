<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OwnerDecision;
use App\Models\Interest;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** OPP-05 — investor is told whether the owner approved sharing the full file. */
class InterestDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(private readonly Interest $interest, private readonly OwnerDecision $decision)
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
        $key = $this->decision === OwnerDecision::Approved ? 'approved' : 'declined';

        $mail = (new MailMessage)
            ->subject(__("notifications.interest_decision.{$key}.subject"))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__("notifications.interest_decision.{$key}.line", ['name' => $opportunity->name]));

        if ($this->decision === OwnerDecision::Approved) {
            $mail->line(__('notifications.interest_decision.approved.nda'))
                ->action(__('notifications.interest_decision.approved.action'), $this->frontendUrl('/app/investor/opportunities/'.$opportunity->uuid));
        }

        return $mail;
    }

    public function toSms(object $notifiable): string
    {
        $key = $this->decision === OwnerDecision::Approved ? 'approved' : 'declined';

        return __("notifications.interest_decision.{$key}.sms");
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $key = $this->decision === OwnerDecision::Approved ? 'approved' : 'declined';

        return [
            'type' => 'interest.decision',
            'title' => __("notifications.interest_decision.{$key}.subject"),
            'body' => __("notifications.interest_decision.{$key}.line", ['name' => $this->interest->opportunity->name]),
            'interest_id' => $this->interest->id,
            'opportunity_uuid' => $this->interest->opportunity->uuid,
            'decision' => $this->decision->value,
        ];
    }
}
