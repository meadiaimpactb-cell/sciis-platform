<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** ACC-01 — email + phone must both be verified ("registered" level). */
class EnsureFullyVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || (! $user->isFullyVerified() && ! config('sciis.verification.optional'))) {
            throw new DomainException(__('messages.auth.verification_required'), 'verification_required', 403, [
                'email_verified' => [$user?->email_verified_at ? 'yes' : 'no'],
                'phone_verified' => [$user?->phone_verified_at ? 'yes' : 'no'],
            ]);
        }

        if (! $user->isActive()) {
            throw new DomainException(__('messages.auth.account_suspended'), 'account_suspended', 403);
        }

        return $next($request);
    }
}
