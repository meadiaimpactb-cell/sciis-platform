<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AssignmentStatus;
use App\Models\Opportunity;
use App\Models\User;

/**
 * ACC-04 — record-level access:
 *  - owner: own opportunities;
 *  - investor: only what `Opportunity::visibleTo()` allows (checked by the query) + their grant level;
 *  - evaluator: only opportunities assigned to them;
 *  - advisory member: only referred files (EVL-04);
 *  - other staff: by permission.
 */
class OpportunityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() && $user->can('opportunities.view');
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        // ACC-08 — entity colleagues read the file (acting ones also edit, through isOwnedBy).
        if ($opportunity->isOwnedBy($user) || $opportunity->isSharedWithColleague($user)) {
            return true;
        }

        if ($user->isStaff()) {
            if ($user->hasRole('evaluator') && ! $user->hasAnyRole(['super_admin', 'reviewer', 'matching_officer'])) {
                // EVL-02: the file opens only after the evaluator declared no conflict of interest.
                return $opportunity->evaluationAssignments()
                    ->where('evaluator_user_id', $user->id)
                    ->where('status', '!=', AssignmentStatus::Withdrawn->value)
                    ->whereNotNull('coi_declared_at')
                    ->where('coi_has_conflict', false)
                    ->exists();
            }
            // EVL-04 — only files actively referred to this member; access ends at the referral's expiry.
            if ($user->hasRole('advisory_member') && ! $user->hasAnyRole(['super_admin', 'reviewer', 'matching_officer'])) {
                return \App\Models\AdvisoryReferral::query()->active()->where('opportunity_id', $opportunity->id)->where('advisory_user_id', $user->id)->exists();
            }

            return $user->can('opportunities.view');
        }

        if ($user->isInvestor()) {
            return $user->isApprovedMember()
                && Opportunity::query()->visibleTo($user)->whereKey($opportunity->id)->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isEntrepreneur() && $user->isFullyVerified() && $user->isActive();
    }

    /** ENT-05 — sections lock after submission (except needs_completion). */
    public function update(User $user, Opportunity $opportunity): bool
    {
        return $opportunity->isOwnedBy($user) && $opportunity->status->isEditableByOwner();
    }

    public function submit(User $user, Opportunity $opportunity): bool
    {
        return $opportunity->isOwnedBy($user) && $opportunity->status->isEditableByOwner();
    }

    public function withdraw(User $user, Opportunity $opportunity): bool
    {
        return $opportunity->isOwnedBy($user) && $opportunity->status->allowsWithdrawal();
    }

    public function manageIndicators(User $user, Opportunity $opportunity): bool
    {
        return $opportunity->isOwnedBy($user) && ! $opportunity->status->isTerminal();
    }

    public function uploadDocument(User $user, Opportunity $opportunity): bool
    {
        return $opportunity->isOwnedBy($user) && ! $opportunity->status->isTerminal();
    }

    public function approveTeaserAsOwner(User $user, Opportunity $opportunity): bool
    {
        return $opportunity->isOwnedBy($user);
    }

    public function transition(User $user, Opportunity $opportunity): bool
    {
        return $user->isStaff() && $user->can('opportunities.transition');
    }

    public function assign(User $user, Opportunity $opportunity): bool
    {
        return $user->isStaff() && ($user->can('opportunities.transition') || $user->can('evaluations.assign'));
    }

    public function evaluate(User $user, Opportunity $opportunity): bool
    {
        return $user->isStaff() && $user->can('evaluations.assign');
    }

    public function decideEvaluation(User $user, Opportunity $opportunity): bool
    {
        return $user->isStaff() && $user->can('evaluations.approve');
    }

    public function manageTeaser(User $user, Opportunity $opportunity): bool
    {
        return $user->isStaff() && $user->can('teasers.approve');
    }

    public function nominate(User $user, Opportunity $opportunity): bool
    {
        return $user->isStaff() && $user->can('nominations.manage');
    }

    public function addNote(User $user, Opportunity $opportunity): bool
    {
        return $user->isStaff() && $user->can('opportunities.view');
    }

    /** Investor-side actions (save / interest / info request) — must be visible to them. */
    public function interact(User $user, Opportunity $opportunity): bool
    {
        // ACC-08 — read-only colleagues of an institutional investor browse but do not act.
        return $user->isInvestor() && $user->canActForOrganization() && $this->view($user, $opportunity);
    }

    /** DOC-02 / ENT-06 — list & download documents only with an active grant (or owner/staff). */
    public function viewDocuments(User $user, Opportunity $opportunity): bool
    {
        if ($opportunity->isOwnedBy($user)) {
            return true;
        }
        if ($user->isStaff()) {
            return $this->view($user, $opportunity);
        }

        return $user->isInvestor() && $user->isApprovedMember() && $opportunity->hasActiveGrantFor($user);
    }
}
