<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Str;

/** In-memory gateway for tests and local development. */
final class FakeGateway implements PaymentGateway
{
    /** @var array<string, array{status: string, payload: array<string, mixed>}> */
    private array $store = [];

    public function __construct(private readonly string $webhookSecret = 'test-webhook-secret') {}

    public function name(): string
    {
        return 'fake';
    }

    public function publishableKey(): string
    {
        return 'pk_test_fake';
    }

    public function create(Payment $payment, string $callbackUrl): array
    {
        $id = 'fake_'.Str::random(16);
        $this->store[$id] = ['status' => PaymentStatus::Pending->value, 'payload' => ['callback_url' => $callbackUrl]];

        return ['gateway_payment_id' => $id, 'status' => PaymentStatus::Pending->value, 'payload' => $this->store[$id]['payload']];
    }

    public function fetch(string $gatewayPaymentId): array
    {
        return $this->store[$gatewayPaymentId] ?? ['status' => PaymentStatus::Pending->value, 'payload' => []];
    }

    public function refund(Payment $payment, ?float $amount = null): array
    {
        $id = (string) $payment->gateway_payment_id;
        $this->store[$id] = ['status' => PaymentStatus::Refunded->value, 'payload' => ['refunded_amount' => $amount ?? (float) $payment->amount]];

        return $this->store[$id];
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature, array $headers = []): bool
    {
        $candidate = $signature ?? ($headers['x-moyasar-signature'] ?? null);
        if (! is_string($candidate)) {
            $decoded = json_decode($rawBody, true);
            $candidate = is_array($decoded) ? ($decoded['secret_token'] ?? null) : null;
        }

        return is_string($candidate) && (hash_equals($this->webhookSecret, $candidate) || hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $candidate));
    }

    public function mapStatus(string $gatewayStatus): string
    {
        return PaymentStatus::tryFrom(strtolower($gatewayStatus))?->value ?? PaymentStatus::Pending->value;
    }

    /** Test helper: mark a payment as paid at the "gateway". */
    public function markPaid(string $gatewayPaymentId): void
    {
        $this->store[$gatewayPaymentId] = ['status' => PaymentStatus::Paid->value, 'payload' => ['paid' => true]];
    }
}
