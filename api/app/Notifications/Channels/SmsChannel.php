<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;

/** Custom notification channel `sms` — delegates to the bound SmsSender driver. */
final class SmsChannel
{
    public function __construct(private readonly SmsSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $to = method_exists($notifiable, 'routeNotificationForSms') ? $notifiable->routeNotificationForSms() : null;
        if (! is_string($to) || $to === '') {
            return;
        }

        $message = $notification->toSms($notifiable);
        if (is_string($message) && $message !== '') {
            $this->sender->send($to, $message);
        }
    }
}
