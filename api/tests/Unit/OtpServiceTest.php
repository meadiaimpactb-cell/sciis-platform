<?php

declare(strict_types=1);

use App\Enums\OtpChannel;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Services\OtpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('issues, verifies and consumes an OTP', function (): void {
    Notification::fake();
    $user = User::factory()->entrepreneur()->unverified()->create();
    $service = app(OtpService::class);

    $otp = $service->issue($user, OtpChannel::Sms);
    Notification::assertSentTo($user, OtpNotification::class);

    // Replace the hash with a known code so we can verify deterministically.
    $otp->forceFill(['code_hash' => Hash::make('123456')])->save();

    expect(fn () => $service->verify($user, OtpChannel::Sms, '000000'))->toThrow(DomainException::class);
    expect($service->verify($user, OtpChannel::Sms, '123456'))->toBeTrue()
        ->and($user->fresh()->phone_verified_at)->not->toBeNull()
        ->and($otp->fresh()->consumed_at)->not->toBeNull();

    // Consumed codes cannot be reused.
    expect(fn () => $service->verify($user, OtpChannel::Sms, '123456'))->toThrow(DomainException::class);
});

it('enforces the resend cooldown', function (): void {
    Notification::fake();
    $user = User::factory()->entrepreneur()->unverified()->create();
    $service = app(OtpService::class);

    $service->issue($user, OtpChannel::Email);
    expect(fn () => $service->issue($user, OtpChannel::Email))->toThrow(DomainException::class);
});
