<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API driver — `POST {endpoint}/{version}/{phone_number_id}/messages` with a template message.
 * Business-initiated messages must use a template approved in WhatsApp Manager (names in `sciis.whatsapp.templates`).
 */
final class CloudApiWhatsAppSender implements WhatsAppSender
{
    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $token,
        private readonly string $apiVersion,
        private readonly string $endpoint,
    ) {}

    public function send(string $to, WhatsAppMessage $message): bool
    {
        if ($this->phoneNumberId === '' || $this->token === '') {
            Log::channel('sms')->warning('WhatsApp Cloud API credentials missing; message not sent', ['to' => $to]);

            return false;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($to, '+'),
            'type' => 'template',
            'template' => [
                'name' => $message->template,
                'language' => ['code' => $message->language],
                'components' => $message->parameters === [] ? [] : [[
                    'type' => 'body',
                    'parameters' => array_map(fn (string $p): array => ['type' => 'text', 'text' => $p], $message->parameters),
                ]],
            ],
        ];

        try {
            $response = Http::withToken($this->token)->acceptJson()->timeout(10)
                ->post(sprintf('%s/%s/%s/messages', rtrim($this->endpoint, '/'), $this->apiVersion, $this->phoneNumberId), $payload);

            if (! $response->successful()) {
                Log::channel('sms')->error('WhatsApp Cloud API error', ['status' => $response->status(), 'body' => $response->body(), 'template' => $message->template]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::channel('sms')->error('WhatsApp Cloud API exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function driver(): string
    {
        return 'cloud_api';
    }
}
