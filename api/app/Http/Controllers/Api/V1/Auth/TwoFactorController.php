<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpChannel;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OtpService;
use App\Support\TwoFactorState;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * ACC-05 — TOTP 2FA for every user (mandatory for staff via `2fa.enforced`).
 *
 *  enable    → secret + otpauth URL + server-rendered QR (SVG data URI); NOT enabled yet
 *  confirm   → first valid TOTP enables 2FA and returns 8 one-time recovery codes (stored encrypted)
 *  challenge → TOTP or recovery code (a used recovery code is invalidated) marks the session/token as passed
 *  disable   → requires BOTH the current password and a valid code (TOTP or recovery code)
 */
class TwoFactorController extends Controller
{
    public const RECOVERY_CODES = 8;

    public function __construct(private readonly Google2FA $google2fa, private readonly AuditLogger $audit, private readonly OtpService $otp) {}

    /**
     * POST /auth/2fa/send-code {channel: email|phone} — ACC-05 alternative to the authenticator app: a one-time
     * login code by e-mail or SMS (SMS only when the phone is verified and an SMS provider is configured).
     * The challenge below accepts it like a TOTP code.
     */
    public function sendCode(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasTwoFactorEnabled()) {
            throw new DomainException(__('messages.auth.two_factor_not_enabled'), 'two_factor_not_enabled', 422);
        }
        $channel = OtpChannel::fromInput($request->input('channel'));
        $available = match ($channel) {
            OtpChannel::Email => $user->email_verified_at !== null,
            OtpChannel::Sms => $user->phone_verified_at !== null && ! $this->otp->deliversToLog(OtpChannel::Sms),
            null => false,
        };
        if (! $available) {
            throw new DomainException(__('messages.auth.two_factor_channel_unavailable'), 'channel_unavailable', 422, ['channel' => [__('messages.auth.two_factor_channel_unavailable')]]);
        }

        $this->otp->issue($user, $channel, 'login');

        return $this->ok([
            'channel' => $channel->publicName(),
            'expires_in' => (int) config('sciis.otp.ttl_minutes', 10) * 60,
            'resend_in' => (int) config('sciis.otp.resend_cooldown_seconds', 60),
        ]);
    }

    /** POST /auth/2fa/enable → {secret, otpauth_url, qr} — 2FA stays OFF until /auth/2fa/confirm. */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            throw new DomainException(__('messages.auth.two_factor_already_enabled'), 'two_factor_enabled', 422);
        }

        $secret = $this->google2fa->generateSecretKey(32);
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->save();

        $issuer = (string) config('sciis.two_factor.issuer', config('app.name'));
        $otpauth = $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret);

        $this->audit->log('auth.2fa_enrolment_started', $user, null, null, $user);

        return $this->ok([
            'secret' => $secret,
            'otpauth_url' => $otpauth,
            'qr' => $this->qrDataUri($otpauth),
            'two_factor_enabled' => false,
        ]);
    }

    /** POST /auth/2fa/confirm {code} → {two_factor_enabled: true, recovery_codes[8]} (shown once). */
    public function confirm(TwoFactorCodeRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            throw new DomainException(__('messages.auth.two_factor_already_enabled'), 'two_factor_enabled', 422);
        }
        if ($user->two_factor_secret === null) {
            throw new DomainException(__('messages.auth.two_factor_not_started'), 'two_factor_not_started', 422);
        }

        if (! $this->validTotp($user, $request->validated('code'))) {
            throw $this->invalidCode();
        }

        $codes = $this->generateRecoveryCodes();
        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = $codes; // `encrypted:array` cast — never stored in clear
        $user->save();

        TwoFactorState::markPassed($request);
        $this->audit->log('auth.2fa_enabled', $user, null, null, $user);

        return $this->ok(['two_factor_enabled' => true, 'recovery_codes' => $codes]);
    }

    /** POST /auth/2fa/challenge {code} — after login when 2FA is enabled (TOTP or recovery code). */
    public function challenge(TwoFactorCodeRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasTwoFactorEnabled()) {
            throw new DomainException(__('messages.auth.two_factor_not_enabled'), 'two_factor_not_enabled', 422);
        }

        $code = (string) $request->validated('code');
        $usedRecovery = false;
        $valid = $this->validTotp($user, $code);
        if (! $valid && $this->consumeRecoveryCode($user, $code)) {
            $valid = $usedRecovery = true;
        }
        // ACC-05: a one-time login code sent by e-mail/SMS (POST /auth/2fa/send-code).
        $loginCode = $valid ? null : $user->otpCodes()->where('purpose', 'login')->usable()->latest('id')->first();
        if ($loginCode !== null) {
            try {
                $valid = $this->otp->verify($user, $loginCode->channel, $code, 'login');
            } catch (DomainException) {
                $valid = false;
            }
        }

        if (! $valid) {
            $this->audit->log('auth.2fa_failed', $user, null, null, $user);
            throw $this->invalidCode();
        }

        $payload = ['two_factor_passed' => true];
        $token = $user->currentAccessToken();
        if ($token !== null && method_exists($token, 'delete')) {
            // Bearer (mobile): swap the `2fa:pending` token for a full one.
            $name = (string) ($token->name ?? 'mobile');
            $token->delete();
            $payload['token'] = $user->createToken($name, ['*', TwoFactorState::ABILITY_PASSED])->plainTextToken;
            $payload['token_type'] = 'Bearer';
        }
        TwoFactorState::markPassed($request);

        if ($usedRecovery) {
            $payload['recovery_codes_remaining'] = count((array) $user->two_factor_recovery_codes);
        }
        $this->audit->log('auth.2fa_passed', $user, null, ['recovery_code' => $usedRecovery], $user);

        return $this->ok($payload);
    }

    /** POST /auth/2fa/disable {password, code} (legacy alias: DELETE /auth/2fa) — not allowed for staff when enforced. */
    public function disable(DisableTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user->isStaff() && config('sciis.two_factor.enforce_for_staff', true)) {
            throw new DomainException(__('messages.auth.two_factor_mandatory_staff'), 'two_factor_mandatory', 403);
        }
        if (! $user->hasTwoFactorEnabled()) {
            throw new DomainException(__('messages.auth.two_factor_not_enabled'), 'two_factor_not_enabled', 422);
        }

        $code = (string) $request->validated('code');
        if (! $this->validTotp($user, $code) && ! $this->consumeRecoveryCode($user, $code)) {
            $this->audit->log('auth.2fa_disable_failed', $user, null, null, $user);
            throw $this->invalidCode();
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();
        $this->audit->log('auth.2fa_disabled', $user, null, null, $user);

        return $this->message(__('messages.auth.two_factor_disabled'));
    }

    private function validTotp(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        try {
            return (bool) $this->google2fa->verifyKey($user->two_factor_secret, $code);
        } catch (Throwable) {
            return false;
        }
    }

    /** Recovery codes are single use: a matching code is removed before returning true. */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $needle = strtoupper($code);
        $codes = array_values(array_filter((array) ($user->two_factor_recovery_codes ?? []), 'is_string'));

        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $needle)) {
                unset($codes[$i]);
                $user->two_factor_recovery_codes = array_values($codes);
                $user->save();

                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function generateRecoveryCodes(): array
    {
        // 10 characters [A-Z0-9] — never the 6-digit TOTP shape; keyed to guarantee uniqueness.
        $codes = [];
        while (count($codes) < self::RECOVERY_CODES) {
            $code = Str::upper(Str::random(10));
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    /** Server-side QR (bacon/bacon-qr-code, SVG back end — no imagick needed) as a data URI for <img src>. */
    private function qrDataUri(string $otpauth): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd)))->writeString($otpauth);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function invalidCode(): DomainException
    {
        return new DomainException(__('messages.auth.two_factor_invalid'), 'two_factor_invalid', 422, ['code' => [__('messages.auth.two_factor_invalid')]]);
    }
}
