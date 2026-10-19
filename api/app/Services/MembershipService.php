<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Exceptions\DomainException;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\MembershipDecisionNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * INV-02 / ADM-06 — membership state machine:
 * registered → under_review → (needs_completion ⇄ under_review) → approved | rejected; approved → suspended → approved.
 */
class MembershipService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    public function ensure(User $user): Membership
    {
        return Membership::query()->firstOrCreate(['user_id' => $user->id], ['status' => MembershipStatus::Registered->value]);
    }

    /** Investor submits the application (profile must be complete enough). */
    public function submit(User $user, bool $acceptTerms): Membership
    {
        $membership = $this->ensure($user);
        $profile = $user->investorProfile;

        if ($profile === null || $profile->completion_percent < 70) {
            throw new DomainException(__('messages.membership.profile_incomplete', ['percent' => $profile?->completion_percent ?? 0]), 'profile_incomplete', 422);
        }

        if (! in_array($membership->status, [MembershipStatus::Registered, MembershipStatus::NeedsCompletion], true)) {
            throw new DomainException(__('messages.membership.already_submitted'), 'membership_already_submitted', 422);
        }

        if (! $acceptTerms) {
            throw new DomainException(__('messages.membership.terms_required'), 'terms_required', 422);
        }

        $required = $this->settings->int('membership.requires_prior_investments', 2);
        if ($required > 0 && (int) ($profile->prior_investments_count ?? 0) < $required) {
            // Not a hard block in the founding phase — surfaced to reviewers through the decision note.
            $membership->completion_note = __('messages.membership.prior_investments_hint', ['count' => $required]);
        }

        return DB::transaction(function () use ($membership, $user): Membership {
            $before = ['status' => $membership->status->value];
            $membership->status = MembershipStatus::UnderReview;
            $membership->submitted_at = now();
            $membership->terms_accepted_at = now();
            $membership->save();

            $this->audit->log('membership.submitted', $membership, $before, ['status' => $membership->status->value], $user);

            return $membership;
        });
    }

    /**
     * Staff transition (ADM-06). `super_admin` or `membership_officer` with `memberships.approve` for approvals.
     *
     * @throws AuthorizationException
     */
    public function transition(Membership $membership, MembershipStatus $to, User $actor, ?string $note = null): Membership
    {
        if (! $membership->status->canTransitionTo($to)) {
            throw new DomainException(__('messages.transitions.not_allowed', ['from' => $membership->status->value, 'to' => $to->value]), 'invalid_transition', 422);
        }

        $needsApprove = in_array($to, [MembershipStatus::Approved, MembershipStatus::Rejected, MembershipStatus::Suspended], true);
        if ($needsApprove ? ! $actor->can('memberships.approve') : ! $actor->can('memberships.review')) {
            throw new AuthorizationException(__('messages.errors.forbidden'));
        }

        if (in_array($to, [MembershipStatus::Rejected, MembershipStatus::Suspended, MembershipStatus::NeedsCompletion], true) && ($note === null || trim($note) === '')) {
            throw new DomainException(__('messages.membership.note_required'), 'note_required', 422);
        }

        return DB::transaction(function () use ($membership, $to, $actor, $note): Membership {
            $before = ['status' => $membership->status->value];
            $membership->status = $to;
            $membership->reviewed_by = $actor->id;
            $membership->reviewed_at = now();

            if ($to === MembershipStatus::Approved) {
                $membership->approved_by = $actor->id;
                $membership->approved_at = now();
                $membership->suspended_reason = null;
            }
            if ($to === MembershipStatus::Suspended) {
                $membership->suspended_reason = $note;
            }
            if ($to === MembershipStatus::NeedsCompletion) {
                $membership->completion_note = $note;
            }
            if ($note !== null) {
                $membership->decision_note = $note;
            }
            $membership->save();

            $this->audit->log('membership.transition', $membership, $before, ['status' => $to->value, 'note' => $note], $actor);

            $this->notifications->send($membership->user, new MembershipDecisionNotification($membership, $to, $to === MembershipStatus::NeedsCompletion ? $note : null));

            return $membership;
        });
    }
}
