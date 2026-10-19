<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Moyasar driver (Mada, Visa/MC, Apple Pay, STC Pay).
 * Amounts are sent in halalas (SAR * 100). Webhooks carry a shared secret that we compare in constant time.
 */
final class MoyasarGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $publishableKey,
        private readonly string $webhookSecret,
        private readonly string $endpoint = 'https://api.moyasar.com/v1',
    ) {}

    public function name(): string
    {
        return 'moyasar';
    }

    public function publishableKey(): string
    {
        return $this->publishableKey;
    }

    public function create(Payment $payment, string $callbackUrl): array
    {
        // Moyasar payments are created client-side with the publishable key (Moyasar.js / mobile SDK);
        // the server only needs to supply the amount/description/callback and later verifies via fetch().
        return [
            'gateway_payment_id' => null,
            'status' => PaymentStatus::Pending->value,
            'payload' => [
                'amount_halalas' => (int) round(((float) $payment->amount) * 100),
                'currency' => $payment->currency,
                'description' => $payment->description,
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'payment_uuid' => $payment->uuid,
                    'purpose' => $payment->purpose->value,
                    'user_uuid' => $payment->user?->uuid,
                ],
            ],
        ];
    }

    public function fetch(string $gatewayPaymentId): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->timeout(15)
            ->get("{$this->endpoint}/payments/{$gatewayPaymentId}");

        if (! $response->successful()) {
            throw new RuntimeException('Moyasar fetch failed: '.$response->status());
        }

        $data = $response->json();

        return ['status' => $this->mapStatus((string) ($data['status'] ?? 'initiated')), 'payload' => is_array($data) ? $data : []];
    }

    public function refund(Payment $payment, ?float $amount = null): array
    {
        if ($payment->gateway_payment_id === null) {
            throw new RuntimeException('Payment has no gateway id');
        }

        $body = $amount !== null ? ['amount' => (int) round($amount * 100)] : [];
        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->timeout(15)
            ->post("{$this->endpoint}/payments/{$payment->gateway_payment_id}/refund", $body);

        if (! $response->successful()) {
            throw new RuntimeException('Moyasar refund failed: '.$response->status());
        }

        $data = $response->json();

        return ['status' => $this->mapStatus((string) ($data['status'] ?? 'refunded')), 'payload' => is_array($data) ? $data : []];
    }

    /**
     * Moyasar webhooks include the shared secret in the JSON body (`secret_token`); some integrations
     * forward it as a header. Both are accepted and compared in constant time.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature, array $headers = []): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }

        $candidates = array_filter([
            $signature,
            $headers['x-moyasar-signature'] ?? null,
            $headers['x-webhook-secret'] ?? null,
        ]);

        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) && isset($decoded['secret_token'])) {
            $candidates[] = (string) $decoded['secret_token'];
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && hash_equals($this->webhookSecret, $candidate)) {
                return true;
            }
            // HMAC variant: hex(HMAC-SHA256(body, secret))
            if (is_string($candidate) && hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function mapStatus(string $gatewayStatus): string
    {
        return match (strtolower($gatewayStatus)) {
            'paid', 'captured' => PaymentStatus::Paid->value,
            'authorized' => PaymentStatus::Authorized->value,
            'failed' => PaymentStatus::Failed->value,
            'refunded' => PaymentStatus::Refunded->value,
            'voided', 'cancelled', 'canceled' => PaymentStatus::Cancelled->value,
            default => PaymentStatus::Pending->value,
        };
    }
}
