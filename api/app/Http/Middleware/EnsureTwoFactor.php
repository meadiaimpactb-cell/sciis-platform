<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Support\TwoFactorState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACC-05 — `2fa.enforced` alias (staff area). TOTP is mandatory for staff when
 * `sciis.two_factor.enforce_for_staff` is on: a staff user without a confirmed 2FA secret gets
 * 403 `two_factor_enrolment_required` (they may only call the /auth/2fa enrolment endpoints).
 * Like `2fa` (EnsureTwoFactorPassed), a user with 2FA enabled must have passed the challenge.
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $enforce = (bool) config('sciis.two_factor.enforce_for_staff', true);

        if ($user->isStaff() && $enforce && ! $user->hasTwoFactorEnabled()) {
            throw new DomainException(__('messages.auth.two_factor_required'), 'two_factor_enrolment_required', 403);
        }

        if (! TwoFactorState::passed($request)) {
            throw new DomainException(__('messages.auth.two_factor_challenge'), 'two_factor_challenge_required', 403);
        }

        return $next($request);
    }
}
