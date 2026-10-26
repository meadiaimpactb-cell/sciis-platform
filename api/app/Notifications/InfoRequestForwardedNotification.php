<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InfoRequest;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** OPP-05 / MSG-01 — owner receives the question WITHOUT the investor's identity. */
class InfoRequestForwardedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(private readonly InfoRequest $infoRequest)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'info_request';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.info_request_forwarded.subject', ['ref' => $this->infoRequest->opportunity->reference_no ?? '']))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.info_request_forwarded.line'))
            ->line('**'.$this->infoRequest->subject.'**')
            ->line($this->infoRequest->body)
            ->action(__('notifications.info_request_forwarded.action'), $this->frontendUrl('/app/entrepreneur/info-requests'));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.info_request_forwarded.sms');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'info_request.forwarded',
            'title' => __('notifications.info_request_forwarded.title'),
            'body' => $this->infoRequest->subject,
            'info_request_id' => $this->infoRequest->id,
            'opportunity_uuid' => $this->infoRequest->opportunity->uuid,
        ];
    }
}
