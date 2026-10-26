<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Notifications\Concerns\RespectsPreferences;
use App\Services\WhatsApp\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** INV-02 / ADM-06 — membership status update for the applicant. */
class MembershipDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(
        private readonly Membership $membership,
        private readonly MembershipStatus $status,
        private readonly ?string $note = null,
    ) {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'membership';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('notifications.membership.subject'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.membership.'.$this->status->value));

        if ($this->note) {
            $mail->line(__('notifications.membership.note', ['note' => $this->note]));
        }

        return $mail->action(__('notifications.membership.action'), $this->frontendUrl('/app/investor/dashboard'));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.membership.sms', ['status' => $this->status->label()]);
    }

    /** NTF-04 — template `membership_decision`: {{1}} status. */
    public function toWhatsApp(object $notifiable): ?WhatsAppMessage
    {
        return WhatsAppMessage::forKey('membership_decision', (string) ($notifiable->locale ?? 'ar'), [$this->status->label()], $this->toSms($notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'membership.decision',
            'title' => __('notifications.membership.subject'),
            'body' => __('notifications.membership.'.$this->status->value),
            'status' => $this->status->value,
            'note' => $this->note,
        ];
    }
}
