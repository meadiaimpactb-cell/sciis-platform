<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACC-03 — the admin area is open to any staff account holding at least one role, including roles created
 * from the roles screen. What each user may do inside it is decided by permissions and policies.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isStaff() || $user->roles()->doesntExist()) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
