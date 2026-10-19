<?php

declare(strict_types=1);

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Events\PaymentPaid;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    config(['sciis.features.payments' => true, 'sciis.payments.gateway' => 'fake', 'services.moyasar.webhook_secret' => 'test-webhook-secret']);
    $this->app->forgetInstance(\App\Services\Payments\Gateways\PaymentGateway::class);
    $this->app->forgetInstance(PaymentService::class);
});

it('returns 404 for payment routes when the feature flag is off', function (): void {
    config(['sciis.features.payments' => false]);
    $user = $this->approvedInvestor();
    $this->actingAs($user, 'api');

    $this->postJson('/api/v1/payments/intents', ['purpose' => 'training', 'amount' => 100, 'idempotency_key' => 'abcdefgh-1'])->assertNotFound();
    $this->postJson('/api/v1/webhooks/moyasar', [])->assertNotFound();
});

it('rejects a webhook with a bad signature and logs the event', function (): void {
    $payload = json_encode(['type' => 'payment_paid', 'data' => ['id' => 'fake_x', 'status' => 'paid']]);

    $this->call('POST', '/api/v1/webhooks/moyasar', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MOYASAR_SIGNATURE' => 'wrong-secret'], $payload)
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_signature');

    $this->assertDatabaseHas('payment_events', ['signature_valid' => 0, 'gateway_event_id' => 'fake_x']);
});

it('accepts a correctly signed webhook, is idempotent and emits PaymentPaid', function (): void {
    Event::fake([PaymentPaid::class]);
    $user = $this->approvedInvestor();
    $this->actingAs($user, 'api');

    $intent = $this->postJson('/api/v1/payments/intents', ['purpose' => PaymentPurpose::Training->value, 'amount' => 500, 'idempotency_key' => 'idem-key-0001'])
        ->assertCreated()->json('data');

    // Same idempotency key → same payment, 200.
    $this->postJson('/api/v1/payments/intents', ['purpose' => PaymentPurpose::Training->value, 'amount' => 500, 'idempotency_key' => 'idem-key-0001'])
        ->assertOk()->assertJsonPath('data.payment_uuid', $intent['payment_uuid']);

    $payload = json_encode(['type' => 'payment_paid', 'data' => ['id' => $intent['gateway_payment_id'], 'status' => 'paid', 'amount' => 50000]]);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MOYASAR_SIGNATURE' => 'test-webhook-secret'];

    $this->call('POST', '/api/v1/webhooks/moyasar', [], [], [], $headers, $payload)->assertOk();
    $this->call('POST', '/api/v1/webhooks/moyasar', [], [], [], $headers, $payload)->assertOk(); // replay

    $payment = Payment::query()->where('uuid', $intent['payment_uuid'])->firstOrFail();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->invoice_id)->not->toBeNull();

    Event::assertDispatchedTimes(PaymentPaid::class, 1);
    $this->assertDatabaseCount('payment_events', 2);
});

it('derives the membership fee from settings, ignoring the client amount', function (): void {
    app(\App\Services\SettingsService::class)->set('membership.fee_sar', 750);
    $user = $this->approvedInvestor();
    $this->actingAs($user, 'api');

    $this->postJson('/api/v1/payments/intents', ['purpose' => 'membership_fee', 'amount' => 1, 'idempotency_key' => 'idem-key-0002'])
        ->assertCreated()->assertJsonPath('data.amount', 750.0);
});
