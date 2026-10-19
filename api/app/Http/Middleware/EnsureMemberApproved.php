<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** INV-02 / SPEC §1.3 — approved_member: membership approved + terms accepted. */
class EnsureMemberApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isInvestor() || ! $user->isApprovedMember()) {
            throw new DomainException(__('messages.membership.not_approved'), 'membership_required', 403);
        }

        return $next($request);
    }
}
