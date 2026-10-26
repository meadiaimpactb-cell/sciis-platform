<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\InfoRequest;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** §6.3 — the council closed an information request before it was answered. */
class InfoRequestClosedNotification extends Notification implements ShouldQueue
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
            ->subject(__('notifications.info_request_closed.subject', ['subject' => $this->infoRequest->subject]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->line())
            ->action(__('notifications.info_request_closed.action'), $this->frontendUrl('/app/investor/info-requests'));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.info_request_closed.sms');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'info_request.closed',
            'title' => __('notifications.info_request_closed.title'),
            'body' => $this->line(),
            'info_request_id' => $this->infoRequest->id,
        ];
    }

    private function line(): string
    {
        return __('notifications.info_request_closed.line', ['subject' => $this->infoRequest->subject, 'name' => $this->infoRequest->opportunity->name]);
    }
}
