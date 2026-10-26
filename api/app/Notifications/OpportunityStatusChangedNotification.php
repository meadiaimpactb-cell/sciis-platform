<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Notifications\Concerns\RespectsPreferences;
use App\Services\WhatsApp\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** NTF-01 — owner is told about every status change (ENT-03/04/07, ADM-01). */
class OpportunityStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(
        private readonly Opportunity $opportunity,
        private readonly ?OpportunityStatus $from,
        private readonly OpportunityStatus $to,
        private readonly ?string $reason = null,
    ) {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'opportunity';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('notifications.status_changed.subject', ['ref' => $this->opportunity->reference_no ?? $this->opportunity->name]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.status_changed.line', ['name' => $this->opportunity->name, 'status' => $this->to->label()]));

        if ($this->reason) {
            $mail->line(__('notifications.status_changed.reason', ['reason' => $this->reason]));
        }

        return $mail->action(__('notifications.status_changed.action'), $this->frontendUrl('/app/entrepreneur/opportunities/'.$this->opportunity->uuid));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.status_changed.sms', ['ref' => $this->opportunity->reference_no ?? '', 'status' => $this->to->label()]);
    }

    /** NTF-04 — template `opportunity_status`: {{1}} name, {{2}} reference, {{3}} status. */
    public function toWhatsApp(object $notifiable): ?WhatsAppMessage
    {
        return WhatsAppMessage::forKey('opportunity_status', (string) ($notifiable->locale ?? 'ar'), [
            $this->opportunity->name, $this->opportunity->reference_no, $this->to->label(),
        ], $this->toSms($notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'opportunity.status_changed',
            'title' => __('notifications.status_changed.title'),
            'body' => __('notifications.status_changed.line', ['name' => $this->opportunity->name, 'status' => $this->to->label()]),
            'opportunity_uuid' => $this->opportunity->uuid,
            'reference_no' => $this->opportunity->reference_no,
            'from' => $this->from?->value,
            'to' => $this->to->value,
            'reason' => $this->reason,
        ];
    }
}
