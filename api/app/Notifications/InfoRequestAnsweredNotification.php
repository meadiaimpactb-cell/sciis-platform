<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InfoRequest;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** OPP-05 — the investor's question was answered (through the council). */
class InfoRequestAnsweredNotification extends Notification implements ShouldQueue
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
            ->subject(__('notifications.info_request.subject', ['subject' => $this->infoRequest->subject]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.info_request.line', ['name' => $this->infoRequest->opportunity->name]))
            ->line((string) $this->infoRequest->owner_answer)
            ->action(__('notifications.info_request.action'), $this->frontendUrl('/app/investor/info-requests'));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.info_request.sms');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'info_request.answered',
            'title' => __('notifications.info_request.title'),
            'body' => __('notifications.info_request.line', ['name' => $this->infoRequest->opportunity->name]),
            'info_request_id' => $this->infoRequest->id,
            'opportunity_uuid' => $this->infoRequest->opportunity->uuid,
        ];
    }
}
