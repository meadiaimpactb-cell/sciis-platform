<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConsentType;
use App\Enums\InterestStatus;
use App\Enums\OpportunityStatus;
use App\Enums\OwnerDecision;
use App\Exceptions\DomainException;
use App\Models\Consent;
use App\Models\FileAccessGrant;
use App\Models\Interest;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Notifications\AccessRevokedNotification;
use App\Notifications\InterestDecisionNotification;
use App\Notifications\InterestReceivedNotification;
use Illuminate\Support\Facades\DB;

/**
 * OPP-05 / ENT-06 / MSG-01 — dual-consent flow:
 *   investor expresses interest → owner decides (approve/decline) → investor accepts NDA → file access grant.
 * Contact data is only exchanged once an interest reaches `connected` (staff action).
 */
class InterestService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /** Investor → "أبدي اهتمامي". Status goes sent → awaiting_owner_approval immediately (SPEC §6). */
    public function express(Opportunity $opportunity, User $investor, ?string $note = null, ?Nomination $nomination = null): Interest
    {
        if ($opportunity->status->isHiddenFromInvestors() || $opportunity->status->isTerminal()) {
            throw new DomainException(__('messages.interest.opportunity_unavailable'), 'opportunity_unavailable', 422);
        }

        $existing = Interest::query()
            ->where('opportunity_id', $opportunity->id)
            ->where('investor_user_id', $investor->id)
            ->open()
            ->first();

        if ($existing !== null) {
            throw new DomainException(__('messages.interest.already_open'), 'interest_already_open', 422);
        }

        return DB::transaction(function () use ($opportunity, $investor, $note, $nomination): Interest {
            $nomination ??= Nomination::query()
                ->where('opportunity_id', $opportunity->id)
                ->where('investor_user_id', $investor->id)
                ->first();

            $interest = Interest::query()->create([
                'opportunity_id' => $opportunity->id,
                'investor_user_id' => $investor->id,
                'nomination_id' => $nomination?->id,
                'status' => InterestStatus::AwaitingOwnerApproval,
                'note' => $note,
                'owner_decision_due_at' => now()->addDays($this->settings->slaDays('owner_decision')),
            ]);

            $this->audit->log('interest.expressed', $interest, null, ['status' => $interest->status->value], $investor);

            // Owner is notified WITHOUT investor identity (MSG-01) — notification carries the interest id only.
            $this->notifications->send($opportunity->owner, new InterestReceivedNotification($interest));

            return $interest;
        });
    }

    /** ENT-06 — owner approves or declines a full-file access request. */
    public function ownerDecide(Interest $interest, User $owner, OwnerDecision $decision, ?string $reason = null, ?int $declineReasonId = null): Interest
    {
        if (! $interest->opportunity->isOwnedBy($owner)) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }
        if ($interest->status !== InterestStatus::AwaitingOwnerApproval) {
            throw new DomainException(__('messages.interest.not_awaiting'), 'interest_not_awaiting', 422);
        }
        if ($decision === OwnerDecision::Declined && $declineReasonId !== null) {
            $valid = TaxonomyTerm::query()->group('decline_reasons')->active()->whereKey($declineReasonId)->exists();
            if (! $valid) {
                throw new DomainException(__('messages.interest.invalid_decline_reason'), 'invalid_decline_reason', 422);
            }
        }

        return DB::transaction(function () use ($interest, $owner, $decision, $reason, $declineReasonId): Interest {
            $before = ['status' => $interest->status->value];
            $interest->owner_decision = $decision;
            $interest->owner_decided_at = now();
            $interest->decline_reason_id = $declineReasonId;

            if ($decision === OwnerDecision::Approved) {
                $interest->status = InterestStatus::Shared;
                $interest->note = $reason ?? $interest->note;

                // Create (or reactivate) the grant — NDA still required before documents open (ENT-06).
                FileAccessGrant::query()->updateOrCreate(
                    ['opportunity_id' => $interest->opportunity_id, 'investor_user_id' => $interest->investor_user_id],
                    ['granted_by_owner_at' => now(), 'revoked_at' => null],
                );
            } else {
                $interest->status = InterestStatus::Closed;
                $interest->closed_reason = $reason;
                $interest->closed_at = now();
            }
            $interest->save();

            $this->audit->log('interest.owner_decision', $interest, $before, ['status' => $interest->status->value, 'decision' => $decision->value], $owner);
            $this->notifications->send($interest->investor, new InterestDecisionNotification($interest, $decision));

            return $interest;
        });
    }

    /**
     * ENT-06 — the owner withdraws a previously approved full-file access: the grant is revoked at once (documents
     * and download URLs are refused from now on), the interest is closed, the investor and matching staff are told.
     */
    public function ownerRevoke(Interest $interest, User $owner, ?string $reason = null): Interest
    {
        if (! $interest->opportunity->isOwnedBy($owner)) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }

        $grant = $interest->accessGrant();
        if ($grant === null || $grant->granted_by_owner_at === null || $grant->revoked_at !== null) {
            throw new DomainException(__('messages.interest.grant_not_active'), 'grant_not_active', 422);
        }

        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        $interest = DB::transaction(function () use ($interest, $grant, $owner, $reason): Interest {
            $before = ['status' => $interest->status->value, 'grant_revoked_at' => null];

            $grant->revoked_at = now();
            $grant->save();

            if ($interest->isOpen()) {
                $interest->status = InterestStatus::Closed;
                $interest->closed_reason = $reason ?? __('messages.interest.revoked_by_owner');
                $interest->closed_at = now();
                $interest->save();
            }

            $this->audit->log('interest.access_revoked', $interest, $before, [
                'status' => $interest->status->value,
                'grant_revoked_at' => $grant->revoked_at->toIso8601String(),
                'reason' => $reason,
            ], $owner);

            return $interest;
        });

        $this->notifications->send($interest->investor, new AccessRevokedNotification($interest));
        $staff = User::query()->staff()->active()->permission('nominations.manage')->get();
        $this->notifications->sendToMany($staff, new AccessRevokedNotification($interest, true, $reason));

        return $interest;
    }

    /**
     * Investor accepts the NDA for an opportunity; activates the grant when the owner already approved.
     * MSG-02: `$version` must be the version currently in force (the one whose text the investor was shown);
     * the consent row keeps version + hash + the full accepted text + language + time + IP + UA.
     */
    public function acceptNda(Opportunity $opportunity, User $investor, string $version, string $ip, ?string $userAgent, ?string $locale = null): FileAccessGrant
    {
        $grant = FileAccessGrant::query()
            ->where('opportunity_id', $opportunity->id)
            ->where('investor_user_id', $investor->id)
            ->whereNull('revoked_at')
            ->first();

        if ($grant === null || $grant->granted_by_owner_at === null) {
            throw new DomainException(__('messages.interest.owner_approval_pending'), 'owner_approval_pending', 422);
        }

        $nda = app(NdaService::class)->current($locale);
        if ($version !== $nda['version']) {
            $message = __('messages.interest.nda_version_mismatch', ['version' => $nda['version']]);
            throw new DomainException($message, 'nda_version_mismatch', 422, ['version' => [$message]]);
        }

        return DB::transaction(function () use ($grant, $opportunity, $investor, $nda, $ip, $userAgent): FileAccessGrant {
            $consent = Consent::query()->create([
                'user_id' => $investor->id,
                'type' => ConsentType::Nda,
                'version' => $nda['version'],
                'text_hash' => $nda['text_hash'],
                'text_snapshot' => $nda['body'],
                'locale' => $nda['locale'],
                'accepted_at' => now(),
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? substr($userAgent, 0, 1000) : null,
                'subject_type' => $opportunity->getMorphClass(),
                'subject_id' => $opportunity->id,
            ]);

            $grant->nda_consent_id = $consent->id;
            $grant->save();

            $this->audit->log('interest.nda_accepted', $grant, null, ['consent_id' => $consent->id, 'version' => $nda['version'], 'text_hash' => $nda['text_hash'], 'locale' => $nda['locale']], $investor);

            return $grant;
        });
    }

    /** Staff moves an interest along (shared → connected → meeting …) — ADM, MAT. */
    public function transition(Interest $interest, InterestStatus $to, User $actor, ?string $reason = null): Interest
    {
        if (! $interest->status->canTransitionTo($to)) {
            throw new DomainException(__('messages.transitions.not_allowed', ['from' => $interest->status->value, 'to' => $to->value]), 'invalid_transition', 422);
        }

        if ($to === InterestStatus::Connected && ! $interest->opportunity->hasActiveGrantFor($interest->investor)) {
            throw new DomainException(__('messages.interest.grant_required_for_connection'), 'grant_required', 422);
        }

        return DB::transaction(function () use ($interest, $to, $actor, $reason): Interest {
            $before = ['status' => $interest->status->value];
            $interest->status = $to;
            if ($to === InterestStatus::Closed) {
                $interest->closed_reason = $reason;
                $interest->closed_at = now();
            }
            $interest->save();

            $this->audit->log('interest.transition', $interest, $before, ['status' => $to->value, 'reason' => $reason], $actor);

            // The opportunity itself becomes `connected` on the first connected interest (SPEC §3 guard).
            if ($to === InterestStatus::Connected && $interest->opportunity->status === OpportunityStatus::Eligible) {
                app(OpportunityStateMachine::class)->transition($interest->opportunity, OpportunityStatus::Connected, $actor, __('messages.interest.auto_connected'));
            }

            return $interest;
        });
    }

    /** Investor closes their own interest. */
    public function closeByInvestor(Interest $interest, User $investor, ?int $reasonId, ?string $note): Interest
    {
        if ($interest->investor_user_id !== $investor->id) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }
        if (! $interest->isOpen()) {
            throw new DomainException(__('messages.interest.already_closed'), 'interest_closed', 422);
        }

        return DB::transaction(function () use ($interest, $investor, $reasonId, $note): Interest {
            $before = ['status' => $interest->status->value];
            $interest->status = InterestStatus::Closed;
            $interest->decline_reason_id = $reasonId;
            $interest->closed_reason = $note;
            $interest->closed_at = now();
            $interest->save();

            FileAccessGrant::query()
                ->where('opportunity_id', $interest->opportunity_id)
                ->where('investor_user_id', $investor->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $this->audit->log('interest.closed_by_investor', $interest, $before, ['status' => 'closed', 'reason_id' => $reasonId], $investor);

            return $interest;
        });
    }
}
