<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\Models\Payment;

/**
 * Gateway abstraction (SPEC §0). Scope: membership fees / paid services / training / sponsorship ONLY.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Public key the client SDK needs (may be empty for fake gateway). */
    public function publishableKey(): string;

    /**
     * Creates/initialises a payment at the gateway and returns gateway data to merge into the Payment.
     *
     * @return array{gateway_payment_id?: string|null, payload?: array<string, mixed>, status?: string}
     */
    public function create(Payment $payment, string $callbackUrl): array;

    /**
     * Fetches the latest state from the gateway.
     *
     * @return array{status: string, payload: array<string, mixed>}
     */
    public function fetch(string $gatewayPaymentId): array;

    /** @return array{status: string, payload: array<string, mixed>} */
    public function refund(Payment $payment, ?float $amount = null): array;

    /** Verifies a webhook payload against the shared secret. */
    public function verifyWebhookSignature(string $rawBody, ?string $signature, array $headers = []): bool;

    /** Maps a gateway status string to PaymentStatus value. */
    public function mapStatus(string $gatewayStatus): string;
}
