<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACC-05 — TOTP is mandatory for staff. A staff user without a confirmed 2FA secret may only
 * call the 2FA enrolment endpoints; a user with 2FA enabled must have passed the challenge
 * for the current session/token (session flag or token ability `2fa:passed`).
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

        if ($user->hasTwoFactorEnabled() && ! $this->passed($request)) {
            throw new DomainException(__('messages.auth.two_factor_challenge'), 'two_factor_challenge_required', 403);
        }

        return $next($request);
    }

    private function passed(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        // Cookie/session authentication (SPA): only the session flag counts — Sanctum's TransientToken
        // answers true to every ability, so it must never be consulted here.
        if ($token === null || $token instanceof \Laravel\Sanctum\TransientToken) {
            return $request->hasSession() && $request->session()->get('2fa.passed') === true;
        }

        // Bearer token (mobile): the token must carry the `2fa:passed` ability.
        return method_exists($token, 'can') && $token->can('2fa:passed');
    }
}
