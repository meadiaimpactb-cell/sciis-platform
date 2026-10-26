<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\AdvisoryReferral;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\AdvisoryReferralNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * EVL-04 — advisory committee referral: staff refer a file to an advisory member with a question and an expiry;
 * the member reads only referred files (OpportunityPolicy) and answers with an opinion until the referral expires;
 * staff read the opinions on the file. Everything is audited and both sides are notified.
 */
final class AdvisoryService
{
    public function __construct(private readonly AuditLogger $audit, private readonly NotificationService $notifications) {}

    /** Active advisory committee members (the referral targets). @return Collection<int, User> */
    public function advisors(): Collection
    {
        return User::query()->staff()->active()->role('advisory_member')->orderBy('name')->get();
    }

    public function refer(Opportunity $opportunity, User $advisor, string $question, Carbon $expiresAt, User $actor): AdvisoryReferral
    {
        if (! $advisor->isStaff() || ! $advisor->isActive() || ! $advisor->hasRole('advisory_member')) {
            throw new DomainException(__('reporting.errors.advisor_invalid'), 'advisor_invalid', 422, ['advisory_user_id' => [__('reporting.errors.advisor_invalid')]]);
        }

        $referral = AdvisoryReferral::query()->create([
            'opportunity_id' => $opportunity->id,
            'advisory_user_id' => $advisor->id,
            'referred_by' => $actor->id,
            'question' => $question,
            'expires_at' => $expiresAt,
        ]);

        $this->audit->log('advisory.referred', $referral, null, ['opportunity_id' => $opportunity->id, 'advisory_user_id' => $advisor->id, 'expires_at' => $expiresAt->toIso8601String()], $actor);
        $this->notifications->send($advisor, new AdvisoryReferralNotification($referral->setRelation('opportunity', $opportunity), 'referred'));

        return $referral;
    }

    public function opine(AdvisoryReferral $referral, User $advisor, string $opinion): AdvisoryReferral
    {
        if ((int) $referral->advisory_user_id !== (int) $advisor->id) {
            throw new DomainException(__('reporting.errors.advisory_not_yours'), 'advisory_not_yours', 403);
        }
        if (! $referral->isActive()) {
            throw new DomainException(__('reporting.errors.advisory_expired'), 'advisory_expired', 409);
        }

        $before = ['opinion' => $referral->opinion !== null];
        $referral->fill(['opinion' => $opinion, 'opined_at' => now()])->save();
        $this->audit->log('advisory.opinion_submitted', $referral, $before, ['opportunity_id' => $referral->opportunity_id, 'length' => mb_strlen($opinion)], $advisor);

        $recipients = $referral->referrer !== null
            ? collect([$referral->referrer])
            : User::query()->staff()->active()->permission('evaluations.approve')->get();
        $this->notifications->sendToMany($recipients, new AdvisoryReferralNotification($referral->loadMissing(['opportunity', 'advisor']), 'opinion'));

        return $referral;
    }

    /** Ends the access now (the opinion, if any, stays on the file). */
    public function end(AdvisoryReferral $referral, User $actor): AdvisoryReferral
    {
        if (! $referral->isActive()) {
            return $referral;
        }

        $before = ['expires_at' => $referral->expires_at?->toIso8601String()];
        $referral->expires_at = now();
        $referral->save();
        $this->audit->log('advisory.access_ended', $referral, $before, ['expires_at' => $referral->expires_at->toIso8601String()], $actor);

        return $referral;
    }

    /** @return array<string, mixed> */
    public function toArray(AdvisoryReferral $r, bool $withOpinion = true): array
    {
        return [
            'id' => $r->id,
            'opportunity' => $r->opportunity ? ['uuid' => $r->opportunity->uuid, 'reference_no' => $r->opportunity->reference_no, 'name' => $r->opportunity->name] : null,
            'advisor' => ['id' => $r->advisory_user_id, 'name' => $r->advisor?->name],
            'referred_by' => $r->referrer ? ['id' => $r->referrer->id, 'name' => $r->referrer->name] : null,
            'question' => $r->question,
            'expires_at' => $r->expires_at?->toIso8601String(),
            'is_active' => $r->isActive(),
            'opinion' => $withOpinion ? $r->opinion : null,
            'opined_at' => $r->opined_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
