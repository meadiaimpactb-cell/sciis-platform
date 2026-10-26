<?php

declare(strict_types=1);

use App\Enums\OtpChannel;
use App\Exceptions\DomainException;
use App\Models\OtpCode;
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
        ->and($otp->fresh())->toBeNull(); // single use: the row is deleted, not just marked consumed

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

it('stores only a hash of a random 6-digit code that expires after 10 minutes', function (): void {
    Notification::fake();
    $user = User::factory()->entrepreneur()->unverified()->create();

    $otp = app(OtpService::class)->issue($user, OtpChannel::Sms);

    $sent = null;
    Notification::assertSentTo($user, OtpNotification::class, function (OtpNotification $n) use ($user, &$sent): bool {
        $sent = $n->toSms($user);

        return true;
    });
    preg_match('/\b(\d{6})\b/', (string) $sent, $m);
    $code = $m[1] ?? '';

    expect($code)->toMatch('/^\d{6}$/')
        ->and($otp->code_hash)->not->toContain($code)
        ->and(Hash::check($code, $otp->code_hash))->toBeTrue()
        ->and((int) round($otp->created_at->diffInMinutes($otp->expires_at, true)))->toBe(10);
});

it('burns the code after the maximum number of wrong attempts', function (): void {
    Notification::fake();
    $user = User::factory()->entrepreneur()->unverified()->create();
    $service = app(OtpService::class);

    $otp = $service->issue($user, OtpChannel::Email);
    $otp->forceFill(['code_hash' => Hash::make('123456')])->save();

    foreach (range(1, (int) config('sciis.otp.max_attempts', 5)) as $_) {
        expect(fn () => $service->verify($user, OtpChannel::Email, '000000'))->toThrow(DomainException::class);
    }

    // Attempts exhausted: even the right code is refused and the row is gone.
    expect(fn () => $service->verify($user, OtpChannel::Email, '123456'))->toThrow(DomainException::class)
        ->and(OtpCode::query()->whereKey($otp->id)->exists())->toBeFalse()
        ->and($user->fresh()->email_verified_at)->toBeNull();
});
