<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Events\PaymentPaid;
use App\Exceptions\DomainException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Payments\Gateways\PaymentGateway;
use Illuminate\Support\Facades\DB;

/**
 * Payments — membership fee / service fee / training / sponsorship ONLY (SPEC §0, §6).
 * Whole module gated by `sciis.features.payments`. Idempotent on `idempotency_key` and `gateway_payment_id`.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
    ) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }

    /** Server decides the amount from settings for membership fees; other purposes accept a client amount. */
    public function amountFor(PaymentPurpose $purpose, ?float $requested): float
    {
        return match ($purpose) {
            PaymentPurpose::MembershipFee => (float) $this->settings->int('membership.fee_sar', 0),
            default => $requested ?? 0.0,
        };
    }

    /** @return array{payment: Payment, created: bool} */
    public function createIntent(User $user, PaymentPurpose $purpose, ?float $requestedAmount, string $idempotencyKey, ?string $description = null): array
    {
        $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if ($existing->user_id !== $user->id) {
                throw new DomainException(__('messages.payments.idempotency_conflict'), 'idempotency_conflict', 409);
            }

            return ['payment' => $existing, 'created' => false];
        }

        $amount = $this->amountFor($purpose, $requestedAmount);
        if ($amount <= 0) {
            throw new DomainException(__('messages.payments.invalid_amount'), 'invalid_amount', 422);
        }

        return DB::transaction(function () use ($user, $purpose, $amount, $idempotencyKey, $description): array {
            $payment = Payment::query()->create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'amount' => $amount,
                'currency' => (string) config('sciis.payments.currency', 'SAR'),
                'status' => PaymentStatus::Pending,
                'gateway' => $this->gateway->name(),
                'idempotency_key' => $idempotencyKey,
                'description' => $description ?? $purpose->label(),
            ]);

            $callback = rtrim((string) config('app.frontend_url'), '/').'/payments/'.$payment->uuid.'/callback';
            $gatewayData = $this->gateway->create($payment, $callback);

            $payment->gateway_payment_id = $gatewayData['gateway_payment_id'] ?? null;
            $payment->gateway_payload = $gatewayData['payload'] ?? null;
            $payment->save();

            $this->audit->log('payment.intent_created', $payment, null, ['amount' => $amount, 'purpose' => $purpose->value], $user);

            return ['payment' => $payment, 'created' => true];
        });
    }

    public function cancel(Payment $payment, User $actor): Payment
    {
        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)) {
            throw new DomainException(__('messages.payments.cannot_cancel'), 'cannot_cancel', 422);
        }

        $before = ['status' => $payment->status->value];
        $payment->status = PaymentStatus::Cancelled;
        $payment->save();
        $this->audit->log('payment.cancelled', $payment, $before, ['status' => 'cancelled'], $actor);

        return $payment;
    }

    /** Pulls the gateway state (used by GET /payments/{uuid} to reconcile when the webhook is late). */
    public function sync(Payment $payment): Payment
    {
        if ($payment->gateway_payment_id === null || in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::Cancelled], true)) {
            return $payment;
        }

        try {
            $remote = $this->gateway->fetch($payment->gateway_payment_id);
        } catch (\Throwable) {
            return $payment;
        }

        return $this->applyStatus($payment, $remote['status'], $remote['payload'], 'sync');
    }

    /**
     * Webhook handler: verify signature, log event, idempotent on gateway_payment_id + status.
     *
     * @param  array<string, string>  $headers
     */
    public function handleWebhook(string $rawBody, ?string $signature, array $headers = []): PaymentEvent
    {
        $valid = $this->gateway->verifyWebhookSignature($rawBody, $signature, $headers);
        $decoded = json_decode($rawBody, true);
        $data = is_array($decoded) ? ($decoded['data'] ?? $decoded) : [];
        $gatewayId = (string) ($data['id'] ?? '');
        $type = (string) ($decoded['type'] ?? ($data['status'] ?? 'unknown'));

        $payment = $gatewayId !== '' ? Payment::query()->where('gateway_payment_id', $gatewayId)->first() : null;
        if ($payment === null && isset($data['metadata']['payment_uuid'])) {
            $payment = Payment::query()->where('uuid', (string) $data['metadata']['payment_uuid'])->first();
            if ($payment !== null && $payment->gateway_payment_id === null && $gatewayId !== '') {
                $payment->gateway_payment_id = $gatewayId;
                $payment->save();
            }
        }

        $event = PaymentEvent::query()->create([
            'payment_id' => $payment?->id,
            'type' => $type,
            'payload' => is_array($decoded) ? $decoded : ['raw' => $rawBody],
            'signature_valid' => $valid,
            'gateway_event_id' => $gatewayId ?: null,
            'created_at' => now(),
        ]);

        if (! $valid) {
            throw new DomainException(__('messages.payments.invalid_signature'), 'invalid_signature', 401);
        }

        if ($payment !== null) {
            $status = $this->gateway->mapStatus((string) ($data['status'] ?? $type));
            $this->applyStatus($payment, $status, is_array($data) ? $data : [], 'webhook');
        }

        return $event;
    }

    public function refund(Payment $payment, User $actor, ?float $amount = null): Payment
    {
        if ($payment->status !== PaymentStatus::Paid) {
            throw new DomainException(__('messages.payments.cannot_refund'), 'cannot_refund', 422);
        }

        $result = $this->gateway->refund($payment, $amount);

        return $this->applyStatus($payment, $result['status'], $result['payload'], 'refund', $actor);
    }

    /** @param array<string, mixed> $payload */
    private function applyStatus(Payment $payment, string $status, array $payload, string $source, ?User $actor = null): Payment
    {
        $new = PaymentStatus::tryFrom($status) ?? PaymentStatus::Pending;
        if ($payment->status === $new) {
            return $payment; // idempotent
        }

        return DB::transaction(function () use ($payment, $new, $payload, $source, $actor): Payment {
            $before = ['status' => $payment->status->value];
            $payment->status = $new;
            $payment->gateway_payload = array_merge((array) $payment->gateway_payload, ['last_'.$source => $payload]);

            if ($new === PaymentStatus::Paid) {
                $payment->paid_at = now();
                $payment->invoice_id ??= $this->issueInvoice($payment)->id;
            }
            if ($new === PaymentStatus::Failed) {
                $payment->failed_reason = (string) ($payload['source']['message'] ?? $payload['message'] ?? 'failed');
            }
            if ($new === PaymentStatus::Refunded) {
                $payment->refunded_at = now();
            }
            $payment->save();

            $this->audit->log('payment.status_changed', $payment, $before, ['status' => $new->value, 'source' => $source], $actor);

            if ($new === PaymentStatus::Paid) {
                PaymentPaid::dispatch($payment); // ShouldDispatchAfterCommit
            }

            return $payment;
        });
    }

    private function issueInvoice(Payment $payment): Invoice
    {
        $vatRate = (float) config('sciis.payments.vat_rate', 0.15);
        $amount = (float) $payment->amount;
        $net = round($amount / (1 + $vatRate), 2);
        $vat = round($amount - $net, 2);

        $number = sprintf('INV-%s-%06d', now()->format('Y'), (Invoice::query()->whereYear('issued_at', now()->year)->count() + 1));

        return Invoice::query()->create([
            'number' => $number,
            'user_id' => $payment->user_id,
            'amount' => $net,
            'vat_rate' => $vatRate,
            'vat_amount' => $vat,
            'total' => $amount,
            'issued_at' => now(),
        ]);
    }
}
