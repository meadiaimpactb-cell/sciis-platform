<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\OtpChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** ACC-01 — one-time code by email or SMS. Never stored in the database channel. */
class OtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly OtpChannel $channel,
        private readonly string $purpose = 'verify',
    ) {
        $this->onQueue('notifications');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channel === OtpChannel::Email ? ['mail'] : ['sms'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('sciis.otp.ttl_minutes', 10);

        return (new MailMessage)
            ->subject(__('notifications.otp.subject', ['app' => config('app.name')]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.otp.line', ['purpose' => __('notifications.otp.purposes.'.$this->purpose)]))
            ->line('**'.$this->code.'**')
            ->line(__('notifications.otp.expires', ['minutes' => $minutes]))
            ->line(__('notifications.otp.ignore'));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.otp.sms', ['code' => $this->code, 'minutes' => (int) config('sciis.otp.ttl_minutes', 10)]);
    }
}
