<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** ACC-05 — TOTP 2FA (mandatory for staff via `2fa.enforced` middleware). */
class TwoFactorController extends Controller
{
    public function __construct(private readonly Google2FA $google2fa, private readonly AuditLogger $audit) {}

    /** POST /auth/2fa/enable → secret + otpauth URL (client renders the QR). */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            throw new DomainException(__('messages.auth.two_factor_already_enabled'), 'two_factor_enabled', 422);
        }

        $secret = $this->google2fa->generateSecretKey(32);
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = null;
        $user->save();

        $issuer = (string) config('sciis.two_factor.issuer', config('app.name'));
        $otpauth = $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret);

        return $this->ok(['secret' => $secret, 'otpauth_url' => $otpauth]);
    }

    /** POST /auth/2fa/confirm {code} */
    public function confirm(TwoFactorCodeRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user->two_factor_secret === null) {
            throw new DomainException(__('messages.auth.two_factor_not_started'), 'two_factor_not_started', 422);
        }

        if (! $this->google2fa->verifyKey($user->two_factor_secret, $request->validated('code'))) {
            throw new DomainException(__('messages.auth.two_factor_invalid'), 'two_factor_invalid', 422, ['code' => [__('messages.auth.two_factor_invalid')]]);
        }

        $codes = collect(range(1, 8))->map(fn (): string => Str::upper(Str::random(10)))->all();
        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = $codes;
        $user->save();

        $this->markPassed($request);
        $this->audit->log('auth.2fa_enabled', $user, null, null, $user);

        return $this->ok(['recovery_codes' => $codes]);
    }

    /** POST /auth/2fa/challenge {code} — after login when 2FA is enabled. */
    public function challenge(TwoFactorCodeRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasTwoFactorEnabled()) {
            throw new DomainException(__('messages.auth.two_factor_not_enabled'), 'two_factor_not_enabled', 422);
        }

        $code = $request->validated('code');
        $valid = $this->google2fa->verifyKey($user->two_factor_secret, $code);

        if (! $valid) {
            $recovery = (array) ($user->two_factor_recovery_codes ?? []);
            if (in_array($code, $recovery, true)) {
                $user->two_factor_recovery_codes = array_values(array_diff($recovery, [$code]));
                $user->save();
                $valid = true;
            }
        }

        if (! $valid) {
            $this->audit->log('auth.2fa_failed', $user, null, null, $user);
            throw new DomainException(__('messages.auth.two_factor_invalid'), 'two_factor_invalid', 422, ['code' => [__('messages.auth.two_factor_invalid')]]);
        }

        $payload = ['two_factor_passed' => true];
        $token = $user->currentAccessToken();
        if ($token !== null && method_exists($token, 'delete')) {
            // Swap the pending token for a full one (mobile).
            $name = (string) ($token->name ?? 'mobile');
            $token->delete();
            $payload['token'] = $user->createToken($name, ['*', '2fa:passed'])->plainTextToken;
            $payload['token_type'] = 'Bearer';
        }
        $this->markPassed($request);
        $this->audit->log('auth.2fa_passed', $user, null, null, $user);

        return $this->ok($payload);
    }

    /** DELETE /auth/2fa — disable (not allowed for staff when enforced). */
    public function disable(TwoFactorCodeRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user->isStaff() && config('sciis.two_factor.enforce_for_staff', true)) {
            throw new DomainException(__('messages.auth.two_factor_mandatory_staff'), 'two_factor_mandatory', 403);
        }
        if (! $user->hasTwoFactorEnabled() || ! $this->google2fa->verifyKey($user->two_factor_secret, $request->validated('code'))) {
            throw new DomainException(__('messages.auth.two_factor_invalid'), 'two_factor_invalid', 422);
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();
        $this->audit->log('auth.2fa_disabled', $user, null, null, $user);

        return $this->message(__('messages.auth.two_factor_disabled'));
    }

    private function markPassed(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put('2fa.passed', true);
        }
    }
}
