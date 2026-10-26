<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\WhatsApp\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Notifications\Notification;

/** NTF-04 — custom notification channel `whatsapp`: delegates `toWhatsApp()` to the bound WhatsAppSender driver. */
final class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $to = method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('whatsapp', $notification) : null;
        if (! is_string($to) || $to === '') {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);
        if ($message instanceof WhatsAppMessage) {
            $this->sender->send($to, $message);
        }
    }
}
