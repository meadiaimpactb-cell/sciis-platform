<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpChannel;
use App\Exceptions\DomainException;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\OtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * ACC-01 / standard verification layer — email + SMS one-time codes.
 *
 *  - 6 random digits (CSPRNG `random_int`), 10-minute TTL, stored HASHED only (bcrypt/argon via Hash);
 *  - at most `sciis.otp.max_attempts` wrong attempts per code, then the code is burned;
 *  - single use: the row is DELETED on successful verification (and when burned / superseded);
 *  - resend cooldown per channel; route-level rate limit `throttle:otp` (5/min per user + channel);
 *  - the plain code is only ever handed to the notification (and, in local/dev with the `log`
 *    mail/SMS driver, written to the log tagged `[OTP][dev]`) — it is never returned by the API.
 */
class OtpService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function issue(User $user, OtpChannel $channel, string $purpose = 'verify'): OtpCode
    {
        $cooldown = (int) config('sciis.otp.resend_cooldown_seconds', 60);
        $last = $user->otpCodes()->where('channel', $channel->value)->where('purpose', $purpose)->latest('id')->first();

        if ($last !== null && $last->consumed_at === null && $last->created_at !== null && $last->created_at->gt(now()->subSeconds($cooldown))) {
            $wait = max(1, $cooldown - (int) $last->created_at->diffInSeconds(now(), true));

            throw new DomainException(__('messages.auth.otp_cooldown', ['seconds' => $wait]), 'otp_cooldown', 429);
        }

        // Only one live code per channel/purpose: previous ones are removed, not kept around.
        $user->otpCodes()->where('channel', $channel->value)->where('purpose', $purpose)->delete();

        $length = (int) config('sciis.otp.length', 6);
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        $otp = $user->otpCodes()->create([
            'channel' => $channel,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('sciis.otp.ttl_minutes', 10)),
        ]);

        $this->logForDevelopment($user, $channel, $purpose, $code);

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
            $otp->delete();
            throw new DomainException(__('messages.auth.otp_attempts'), 'otp_attempts_exceeded', 422, ['code' => [__('messages.auth.otp_attempts')]]);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            throw new DomainException(__('messages.auth.otp_invalid'), 'otp_invalid', 422, ['code' => [__('messages.auth.otp_invalid')]]);
        }

        // Single use: the code row is gone the moment it has been accepted.
        $otp->delete();

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

    /** True when the delivery driver for the channel only writes to the log (local / dev). */
    public function deliversToLog(OtpChannel $channel): bool
    {
        return $channel === OtpChannel::Email
            ? (string) config('mail.default') === 'log'
            : (string) config('sciis.sms.driver', 'log') === 'log';
    }

    /** Local/dev only: no real mail/SMS delivery, so the code is readable from the application log. */
    private function logForDevelopment(User $user, OtpChannel $channel, string $purpose, string $code): void
    {
        if (app()->isProduction() || ! $this->deliversToLog($channel)) {
            return;
        }

        Log::info(sprintf('[OTP][dev] %s code for user #%d (%s): %s', $channel->publicName(), $user->id, $purpose, $code), [
            'user_id' => $user->id,
            'channel' => $channel->publicName(),
            'purpose' => $purpose,
            'to' => $channel === OtpChannel::Email ? $user->email : $user->phone,
            'code' => $code,
            'expires_in_minutes' => (int) config('sciis.otp.ttl_minutes', 10),
        ]);
    }
}
