<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EvaluationAssignment;
use App\Models\User;

/** ACC-04 / EVL-02 — only the assigned evaluator may declare COI or score. */
class EvaluationAssignmentPolicy
{
    public function view(User $user, EvaluationAssignment $assignment): bool
    {
        return $assignment->evaluator_user_id === $user->id
            || ($user->isStaff() && $user->can('evaluations.approve'));
    }

    public function declareCoi(User $user, EvaluationAssignment $assignment): bool
    {
        return $assignment->evaluator_user_id === $user->id && $user->can('evaluations.score');
    }

    public function score(User $user, EvaluationAssignment $assignment): bool
    {
        return $assignment->evaluator_user_id === $user->id && $user->can('evaluations.score') && $assignment->canScore();
    }
}
