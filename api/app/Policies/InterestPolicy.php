<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Interest;
use App\Models\User;

class InterestPolicy
{
    public function view(User $user, Interest $interest): bool
    {
        // ACC-08 — colleagues of an institutional investor see the organisation's interests.
        if ($interest->investor_user_id === $user->id || ($user->isInvestor() && in_array($interest->investor_user_id, $user->organizationPeerIds(), true))) {
            return true;
        }
        if ($interest->opportunity->isOwnedBy($user)) {
            return true; // owner sees the request but NOT the investor identity (resource strips it)
        }

        return $user->isStaff() && $user->can('nominations.manage');
    }

    public function close(User $user, Interest $interest): bool
    {
        $ownSide = $interest->investor_user_id === $user->id
            || ($user->isInvestor() && $user->canActForOrganization() && in_array($interest->investor_user_id, $user->organizationPeerIds(), true)); // ACC-08

        return $ownSide && $interest->isOpen();
    }

    /** ENT-06 — only the opportunity owner decides. */
    public function decide(User $user, Interest $interest): bool
    {
        return $interest->opportunity->isOwnedBy($user);
    }

    /** ENT-06 — only the opportunity owner revokes an access they approved. */
    public function revoke(User $user, Interest $interest): bool
    {
        return $interest->opportunity->isOwnedBy($user);
    }

    public function transition(User $user, Interest $interest): bool
    {
        return $user->isStaff() && $user->can('nominations.manage');
    }
}
