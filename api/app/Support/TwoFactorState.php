<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;

/**
 * ACC-05 — has the current session/token passed the TOTP challenge?
 *
 *  - Cookie session (SPA): the session flag `2fa.passed` (set at login for users without 2FA,
 *    and by /auth/2fa/confirm or /auth/2fa/challenge).
 *  - Bearer token (mobile): the token must carry the `2fa:passed` ability (or `*`, i.e. a token
 *    issued while 2FA was off). Login with 2FA on issues a `2fa:pending`-only token.
 */
final class TwoFactorState
{
    public const SESSION_KEY = '2fa.passed';

    public const ABILITY_PASSED = '2fa:passed';

    public const ABILITY_PENDING = '2fa:pending';

    public static function passed(Request $request): bool
    {
        $user = $request->user();
        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            return true;
        }

        $token = $user->currentAccessToken();

        // Cookie/session authentication: only the session flag counts — Sanctum's TransientToken
        // answers true to every ability, so it must never be consulted here.
        if ($token === null || $token instanceof TransientToken) {
            return $request->hasSession() && $request->session()->get(self::SESSION_KEY) === true;
        }

        return method_exists($token, 'can') && $token->can(self::ABILITY_PASSED);
    }

    public static function markPassed(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, true);
        }
    }
}
