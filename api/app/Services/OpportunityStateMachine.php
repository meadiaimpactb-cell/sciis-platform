<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EvaluationResult;
use App\Enums\InterestStatus;
use App\Enums\OpportunityStatus;
use App\Events\OpportunityStatusChanged;
use App\Exceptions\InvalidTransitionException;
use App\Models\Opportunity;
use App\Models\OpportunityStatusTransition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADM-01 — the ONLY way an opportunity changes status (SPEC §3).
 *
 * Validates the allowed-transition map, checks permission (actor role + record ownership),
 * verifies guards (eligible ⇒ evaluation result + approved teaser; connected ⇒ connected interest),
 * writes `opportunity_status_transitions`, an `audit_logs` row, computes SLA due dates and
 * fires `OpportunityStatusChanged` (notifications).
 */
class OpportunityStateMachine
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly ReferenceNumberGenerator $references,
    ) {}

    /**
     * Allowed transition map (SPEC §3). `withdrawn` (owner, before connected) and `closed` (admin)
     * are reachable from "any" state and handled in allowedTargets().
     *
     * @return array<string, array<int, OpportunityStatus>>
     */
    public static function map(): array
    {
        return [
            OpportunityStatus::Draft->value => [OpportunityStatus::New],
            OpportunityStatus::New->value => [OpportunityStatus::UnderReview, OpportunityStatus::NeedsCompletion, OpportunityStatus::NotEligible],
            OpportunityStatus::UnderReview->value => [OpportunityStatus::Eligible, OpportunityStatus::NeedsCompletion, OpportunityStatus::NotEligible],
            OpportunityStatus::NeedsCompletion->value => [OpportunityStatus::UnderReview],
            OpportunityStatus::NotEligible->value => [OpportunityStatus::New], // resubmission allowed
            OpportunityStatus::Eligible->value => [OpportunityStatus::Connected],
            OpportunityStatus::Connected->value => [OpportunityStatus::Negotiating],
            OpportunityStatus::Negotiating->value => [OpportunityStatus::InExecution],
            OpportunityStatus::InExecution->value => [OpportunityStatus::Operating],
            OpportunityStatus::Operating->value => [],
            OpportunityStatus::Withdrawn->value => [],
            OpportunityStatus::Closed->value => [],
        ];
    }

    /** @return array<int, OpportunityStatus> */
    public function allowedTargets(OpportunityStatus $from): array
    {
        $targets = self::map()[$from->value] ?? [];

        if (! $from->isTerminal()) {
            $targets[] = OpportunityStatus::Closed; // any (admin decision)
        }
        if ($from->allowsWithdrawal()) {
            $targets[] = OpportunityStatus::Withdrawn; // any owner-initiated, before connected
        }

        $unique = [];
        foreach ($targets as $target) {
            $unique[$target->value] = $target;
        }

        return array_values($unique);
    }

    public function canTransition(OpportunityStatus $from, OpportunityStatus $to): bool
    {
        return in_array($to, $this->allowedTargets($from), true);
    }

    /**
     * Targets this actor may pick (map ∩ permissions — business guards are still checked on submit), so the
     * staff UI never offers a decision the actor cannot sign (ADM-07: not_eligible needs the supervisor).
     *
     * @return array<int, OpportunityStatus>
     */
    public function allowedTargetsFor(Opportunity $opportunity, User $actor): array
    {
        return array_values(array_filter(
            $this->allowedTargets($opportunity->status),
            fn (OpportunityStatus $to): bool => $this->permits($opportunity, $to, $actor),
        ));
    }

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidTransitionException|AuthorizationException
     */
    public function transition(Opportunity $opportunity, OpportunityStatus $to, User $actor, ?string $reason = null, array $metadata = []): Opportunity
    {
        $from = $opportunity->status;

        if (! $this->canTransition($from, $to)) {
            throw InvalidTransitionException::forStatuses($from->value, $to->value);
        }

        $this->authorize($opportunity, $to, $actor);
        $this->guard($opportunity, $to, $reason);

        return $this->persist($opportunity, $from, $to, $actor, $reason, $metadata);
    }

    /**
     * Journey 11.3 — a transition performed by the platform itself (scheduled automation, no actor): same map,
     * guards, log, audit and owner notification; only the actor permission check does not apply.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidTransitionException
     */
    public function systemTransition(Opportunity $opportunity, OpportunityStatus $to, string $reason, array $metadata = []): Opportunity
    {
        $from = $opportunity->status;

        if (! $this->canTransition($from, $to)) {
            throw InvalidTransitionException::forStatuses($from->value, $to->value);
        }

        $this->guard($opportunity, $to, $reason);

        return $this->persist($opportunity, $from, $to, null, $reason, $metadata + ['system' => true]);
    }

    /** @param array<string, mixed> $metadata */
    private function persist(Opportunity $opportunity, OpportunityStatus $from, OpportunityStatus $to, ?User $actor, ?string $reason, array $metadata): Opportunity
    {
        return DB::transaction(function () use ($opportunity, $from, $to, $actor, $reason, $metadata): Opportunity {
            $before = ['status' => $from->value];

            $opportunity->status = $to;
            $this->applySideEffects($opportunity, $from, $to);
            $opportunity->save();

            OpportunityStatusTransition::query()->create([
                'opportunity_id' => $opportunity->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_user_id' => $actor?->id,
                'reason' => $reason,
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);

            $this->audit->log('opportunity.transition', $opportunity, $before, [
                'status' => $to->value,
                'reason' => $reason,
                'reference_no' => $opportunity->reference_no,
            ], $actor);

            OpportunityStatusChanged::dispatch($opportunity, $from, $to, $actor, $reason); // ShouldDispatchAfterCommit

            return $opportunity->refresh();
        });
    }

    /**
     * Who may perform which transition.
     *
     * @throws AuthorizationException
     */
    private function authorize(Opportunity $opportunity, OpportunityStatus $to, User $actor): void
    {
        if (! $this->permits($opportunity, $to, $actor)) {
            // ADM-07 — a reviewer holding opportunities.transition gets the specific "supervisor sign-off" message.
            $supervisorOnly = $to === OpportunityStatus::NotEligible && $actor->isStaff() && $actor->can('opportunities.transition');

            throw new AuthorizationException(__($supervisorOnly ? 'messages.transitions.supervisor_required' : 'messages.transitions.forbidden'));
        }
    }

    /** Who may perform which transition (permission + ownership only; no business guards). */
    public function permits(Opportunity $opportunity, OpportunityStatus $to, User $actor): bool
    {
        $isOwner = $opportunity->isOwnedBy($actor);
        $isStaff = $actor->isStaff();
        // ADM-01 — the staff permission per target status is configurable (`workflow.transition_permissions`,
        // e.g. {"in_execution": "agreements.manage"}); unmapped targets keep `opportunities.transition`.
        $map = (array) $this->settings->get('workflow.transition_permissions', []);
        $mapped = is_string($map[$to->value] ?? null) ? $map[$to->value] : null;
        $staffMay = $isStaff && $actor->can($mapped ?? 'opportunities.transition');

        return match ($to) {
            // Owner submits / re-submits.
            OpportunityStatus::New => $isOwner || $staffMay,
            // Re-submission after completion is owner's; initial screening (new→under_review) is staff's.
            OpportunityStatus::UnderReview => ($isOwner && $opportunity->status === OpportunityStatus::NeedsCompletion) || $staffMay,
            OpportunityStatus::Withdrawn => $isOwner || $staffMay,
            OpportunityStatus::Closed => $isStaff && ($actor->hasRole('super_admin') || $staffMay),
            // Connection is driven by the matching officer (nominations.manage) once dual consent is satisfied.
            OpportunityStatus::Connected => $isStaff && (($mapped === null && $actor->can('nominations.manage')) || $staffMay),
            // ADM-07 — the final rejection needs the supervisor's sign-off (`evaluations.approve`) on top of the
            // transition permission; the reviewer keeps screening, completion requests and eligible. Reason: guard().
            OpportunityStatus::NotEligible => $staffMay && $actor->can('evaluations.approve'),
            default => $staffMay,
        };
    }

    /** Business guards from SPEC §3 "Rules". */
    private function guard(Opportunity $opportunity, OpportunityStatus $to, ?string $reason): void
    {
        switch ($to) {
            case OpportunityStatus::NotEligible:
                if ($reason === null || trim($reason) === '') {
                    throw InvalidTransitionException::guardFailed('messages.transitions.reason_required');
                }
                break;

            case OpportunityStatus::Eligible:
                $result = $opportunity->evaluation_result;
                if (! in_array($result, [EvaluationResult::Eligible, EvaluationResult::EligibleWithConditions], true)) {
                    throw InvalidTransitionException::guardFailed('messages.transitions.evaluation_required');
                }
                if (! $opportunity->hasApprovedTeaser()) {
                    throw InvalidTransitionException::guardFailed('messages.transitions.teaser_required');
                }
                if (! $opportunity->hasTeaserPublicationConsent()) {
                    throw InvalidTransitionException::guardFailed('messages.transitions.teaser_consent_required');
                }
                break;

            case OpportunityStatus::Connected:
                $hasConnected = $opportunity->interests()->where('status', InterestStatus::Connected->value)->exists();
                if (! $hasConnected) {
                    throw InvalidTransitionException::guardFailed('messages.transitions.connected_interest_required');
                }
                break;

            case OpportunityStatus::Withdrawn:
                if (! $opportunity->status->allowsWithdrawal()) {
                    throw InvalidTransitionException::guardFailed('messages.transitions.withdraw_not_allowed');
                }
                break;

            default:
                break;
        }
    }

    /** Reference number, submitted_at and SLA due dates (ADM-03). */
    private function applySideEffects(Opportunity $opportunity, OpportunityStatus $from, OpportunityStatus $to): void
    {
        $now = now();

        if ($to === OpportunityStatus::New) {
            $opportunity->submitted_at = $now;
            if ($opportunity->reference_no === null) {
                $opportunity->reference_no = $this->references->next();
            }
            $opportunity->review_due_at = $now->copy()->addDays($this->settings->slaDays('review'));
            $opportunity->completion_due_at = null;
        }

        if ($to === OpportunityStatus::NeedsCompletion) {
            $opportunity->completion_due_at = $now->copy()->addDays($this->settings->slaDays('completion'));
        }

        if ($to === OpportunityStatus::UnderReview) {
            $opportunity->completion_due_at = null;
            $opportunity->evaluation_due_at = $now->copy()->addDays($this->settings->slaDays('evaluation'));
            $opportunity->completionRequests()->open()->update(['resolved_at' => $now]);
        }

        if ($to === OpportunityStatus::Eligible) {
            $opportunity->evaluation_approved_at ??= $now;
        }

        // ADM-03 — post-evaluation stages: one deadline per stage (eligible → matching, connected, negotiating).
        $stageKind = Opportunity::STAGE_SLA_KINDS[$to->value] ?? null;
        $opportunity->stage_due_at = $stageKind !== null ? $now->copy()->addDays($this->settings->slaDays($stageKind)) : null;

        if ($to->isTerminal()) {
            $opportunity->is_listed = false;
        }
    }
}
