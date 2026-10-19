<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unifonic (KSA) driver stub — wired to the REST endpoint; credentials from config/services.php.
 * Sender id comes from settings `notifications.sms_sender`.
 */
final class UnifonicSmsSender implements SmsSender
{
    public function __construct(
        private readonly string $appSid,
        private readonly string $senderId,
        private readonly string $endpoint,
    ) {}

    public function send(string $to, string $message): bool
    {
        if ($this->appSid === '') {
            Log::channel('sms')->warning('Unifonic AppSid missing; SMS not sent', ['to' => $to]);

            return false;
        }

        try {
            $response = Http::asForm()->timeout(10)->post($this->endpoint, [
                'AppSid' => $this->appSid,
                'SenderID' => $this->senderId,
                'Recipient' => ltrim($to, '+'),
                'Body' => $message,
            ]);

            if (! $response->successful()) {
                Log::channel('sms')->error('Unifonic error', ['status' => $response->status(), 'body' => $response->body()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::channel('sms')->error('Unifonic exception', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
