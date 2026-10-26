<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MeetingAction;
use App\Models\User;

class MeetingActionPolicy
{
    /** MTG-02 — staff managing meetings, or the person the action is assigned to, mark it done / undone. */
    public function update(User $user, MeetingAction $action): bool
    {
        if ($action->owner_user_id !== null && $action->owner_user_id === $user->id) {
            return true;
        }

        return $user->isStaff() && $user->can('meetings.manage');
    }
}
