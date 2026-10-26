<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;

/** Development / test driver (default): writes WhatsApp messages to the `sms` log channel. */
final class LogWhatsAppSender implements WhatsAppSender
{
    public function send(string $to, WhatsAppMessage $message): bool
    {
        Log::channel('sms')->info('WhatsApp', [
            'to' => $to,
            'template' => $message->template,
            'language' => $message->language,
            'parameters' => $message->parameters,
            'text' => $message->text,
        ]);

        return true;
    }

    public function driver(): string
    {
        return 'log';
    }
}
