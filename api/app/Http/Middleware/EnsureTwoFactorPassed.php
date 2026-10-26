<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Support\TwoFactorState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACC-05 — `2fa` alias. ANY user (investor, entrepreneur, staff) with 2FA enabled must have passed
 * the TOTP challenge for the current session/token before reaching a protected resource:
 * 403 `two_factor_challenge_required` otherwise. Applied to every `auth:sanctum` route except
 * GET /me, POST /auth/logout and POST /auth/2fa/challenge (routes/api.php).
 */
class EnsureTwoFactorPassed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! TwoFactorState::passed($request)) {
            throw new DomainException(__('messages.auth.two_factor_challenge'), 'two_factor_challenge_required', 403);
        }

        return $next($request);
    }
}
