<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpChannel;
use App\Exceptions\DomainException;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\OtpNotification;
use Illuminate\Support\Facades\Hash;

/**
 * ACC-01 — email + SMS OTP verification with expiry, attempt limits and resend cooldown.
 */
class OtpService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function issue(User $user, OtpChannel $channel, string $purpose = 'verify'): OtpCode
    {
        $cooldown = (int) config('sciis.otp.resend_cooldown_seconds', 60);
        $last = $user->otpCodes()->where('channel', $channel->value)->where('purpose', $purpose)->latest('id')->first();

        if ($last !== null && $last->consumed_at === null && $last->created_at !== null && $last->created_at->gt(now()->subSeconds($cooldown))) {
            throw new DomainException(__('messages.auth.otp_cooldown', ['seconds' => $cooldown]), 'otp_cooldown', 429);
        }

        // Invalidate previous codes for the same channel/purpose.
        $user->otpCodes()->where('channel', $channel->value)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $length = (int) config('sciis.otp.length', 6);
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        $otp = $user->otpCodes()->create([
            'channel' => $channel,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('sciis.otp.ttl_minutes', 10)),
        ]);

        $user->notify(new OtpNotification($code, $channel, $purpose));

        $this->audit->log('otp.issued', $otp, null, ['channel' => $channel->value, 'purpose' => $purpose], $user);

        return $otp;
    }

    public function verify(User $user, OtpChannel $channel, string $code, string $purpose = 'verify'): bool
    {
        $otp = $user->otpCodes()->where('channel', $channel->value)->where('purpose', $purpose)->usable()->latest('id')->first();

        if ($otp === null) {
            throw new DomainException(__('messages.auth.otp_expired'), 'otp_expired', 422, ['code' => [__('messages.auth.otp_expired')]]);
        }

        if ($otp->attempts >= (int) config('sciis.otp.max_attempts', 5)) {
            $otp->update(['consumed_at' => now()]);
            throw new DomainException(__('messages.auth.otp_attempts'), 'otp_attempts_exceeded', 422, ['code' => [__('messages.auth.otp_attempts')]]);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            throw new DomainException(__('messages.auth.otp_invalid'), 'otp_invalid', 422, ['code' => [__('messages.auth.otp_invalid')]]);
        }

        $otp->update(['consumed_at' => now()]);

        if ($purpose === 'verify') {
            if ($channel === OtpChannel::Email && $user->email_verified_at === null) {
                $user->email_verified_at = now();
            }
            if ($channel === OtpChannel::Sms && $user->phone_verified_at === null) {
                $user->phone_verified_at = now();
            }
            $user->save();
        }

        $this->audit->log('otp.verified', $otp, null, ['channel' => $channel->value, 'purpose' => $purpose], $user);

        return true;
    }
}
