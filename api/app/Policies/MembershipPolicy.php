<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;

class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() && $user->can('memberships.review');
    }

    public function view(User $user, Membership $membership): bool
    {
        return $membership->user_id === $user->id || ($user->isStaff() && $user->can('memberships.review'));
    }

    public function review(User $user, Membership $membership): bool
    {
        return $user->isStaff() && $user->can('memberships.review');
    }

    public function approve(User $user, Membership $membership): bool
    {
        return $user->isStaff() && $user->can('memberships.approve');
    }
}
