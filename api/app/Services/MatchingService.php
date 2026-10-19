<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Exceptions\DomainException;
use App\Models\InvestorProfile;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\NominationSentNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MAT-01..04 — rule-based candidate ranking (no black box, MAT-02):
 * match_score = number of matched criteria among {sector, stage, region, investment_type, funding_range}.
 * Investors who declared a conflict of interest with the opportunity's owner/organization are excluded (MAT-04).
 */
class MatchingService
{
    public const CRITERIA = ['sector', 'stage', 'region', 'investment_type', 'funding_range'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Returns approved investors ranked by match score (desc), each with the matched criteria list.
     *
     * @return Collection<int, array{user: User, score: int, matched: array<int, string>, already_nominated: bool}>
     */
    public function candidates(Opportunity $opportunity, int $limit = 50): Collection
    {
        $opportunity->loadMissing(['regions', 'investmentTypes', 'sector', 'fundingRange', 'owner', 'organization']);

        $regionIds = $opportunity->regions->pluck('id')->all();
        $typeIds = $opportunity->investmentTypes->pluck('id')->all();
        $stageKey = $opportunity->stage?->value;
        $alreadyNominated = array_map('intval', $opportunity->nominations()->pluck('investor_user_id')->all());

        $profiles = InvestorProfile::query()
            ->with(['user', 'sectors', 'stages', 'investmentTypes', 'regions', 'investmentRange'])
            ->whereHas('user', function ($q): void {
                $q->investors()->active()->whereHas('membership', fn ($m) => $m->where('status', MembershipStatus::Approved->value)->whereNotNull('terms_accepted_at'));
            })
            ->get();

        $ranked = $profiles
            ->reject(fn (InvestorProfile $p): bool => $this->hasDeclaredConflict($p, $opportunity))
            ->map(function (InvestorProfile $p) use ($opportunity, $regionIds, $typeIds, $stageKey, $alreadyNominated): array {
                $matched = $this->matchedCriteria($p, $opportunity, $regionIds, $typeIds, $stageKey);

                return [
                    'user' => $p->user,
                    'score' => count($matched),
                    'matched' => $matched,
                    'already_nominated' => in_array((int) $p->user_id, $alreadyNominated, true),
                ];
            })
            ->sortByDesc(fn (array $row): string => sprintf('%02d-%010d', $row['score'], PHP_INT_MAX - ($row['user']->id ?? 0)))
            ->values();

        return $ranked->take($limit);
    }

    /** @return array<int, string> */
    public function matchedCriteria(InvestorProfile $profile, Opportunity $opportunity, ?array $regionIds = null, ?array $typeIds = null, ?string $stageKey = null): array
    {
        $regionIds ??= $opportunity->regions->pluck('id')->all();
        $typeIds ??= $opportunity->investmentTypes->pluck('id')->all();
        $stageKey ??= $opportunity->stage?->value;

        $matched = [];

        if ($opportunity->sector_id !== null && $profile->sectors->contains(fn ($t): bool => (int) $t->id === (int) $opportunity->sector_id)) {
            $matched[] = 'sector';
        }
        if ($stageKey !== null && $profile->stages->contains('key', $stageKey)) {
            $matched[] = 'stage';
        }
        if ($regionIds !== [] && $profile->regions->pluck('id')->intersect($regionIds)->isNotEmpty()) {
            $matched[] = 'region';
        }
        if ($typeIds !== [] && $profile->investmentTypes->pluck('id')->intersect($typeIds)->isNotEmpty()) {
            $matched[] = 'investment_type';
        }
        if ($opportunity->funding_range_id !== null && $profile->investment_range_id !== null && (int) $profile->investment_range_id === (int) $opportunity->funding_range_id) {
            $matched[] = 'funding_range';
        }

        return $matched;
    }

    /**
     * MAT-04 — simple, transparent COI rule: the investor's free-text conflicts disclosure mentions
     * the opportunity name, the organization name or the owner's name.
     */
    public function hasDeclaredConflict(InvestorProfile $profile, Opportunity $opportunity): bool
    {
        $disclosure = mb_strtolower(trim((string) $profile->conflicts_disclosure));
        if ($disclosure === '') {
            return false;
        }

        $needles = array_filter([
            mb_strtolower((string) $opportunity->name),
            mb_strtolower((string) ($opportunity->organization?->name ?? '')),
            mb_strtolower((string) ($opportunity->owner?->name ?? '')),
        ], fn (string $n): bool => mb_strlen($n) >= 3);

        foreach ($needles as $needle) {
            if (str_contains($disclosure, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * MAT-01/03 — nominate investors with a rationale; respects `opportunity.max_nominations_per_opportunity`.
     *
     * @param  array<int, int>  $investorUserIds
     * @return Collection<int, Nomination>
     */
    public function nominate(Opportunity $opportunity, array $investorUserIds, string $rationale, User $actor): Collection
    {
        if (! $opportunity->hasApprovedTeaser()) {
            throw new DomainException(__('messages.matching.teaser_required'), 'teaser_required', 422);
        }

        $max = $this->settings->int('opportunity.max_nominations_per_opportunity', 5);
        $current = $opportunity->nominations()->count();
        $new = array_values(array_unique(array_map('intval', $investorUserIds)));

        if ($current + count($new) > $max) {
            throw new DomainException(__('messages.matching.max_nominations', ['max' => $max]), 'max_nominations_exceeded', 422);
        }

        $teaserVersion = $opportunity->teaser?->version;

        return DB::transaction(function () use ($opportunity, $new, $rationale, $actor, $teaserVersion): Collection {
            $created = collect();

            foreach ($new as $userId) {
                /** @var User|null $investor */
                $investor = User::query()->investors()->with('investorProfile')->find($userId);
                if ($investor === null || ! $investor->isApprovedMember()) {
                    throw new DomainException(__('messages.matching.investor_not_eligible', ['id' => $userId]), 'investor_not_eligible', 422);
                }
                $profile = $investor->investorProfile;
                if ($profile !== null && $this->hasDeclaredConflict($profile, $opportunity)) {
                    throw new DomainException(__('messages.matching.coi_excluded', ['id' => $userId]), 'coi_excluded', 422);
                }

                $matched = $profile ? $this->matchedCriteria($profile->load(['sectors', 'stages', 'investmentTypes', 'regions']), $opportunity) : [];

                $nomination = Nomination::query()->updateOrCreate(
                    ['opportunity_id' => $opportunity->id, 'investor_user_id' => $investor->id],
                    [
                        'nominated_by' => $actor->id,
                        'matched_criteria' => $matched,
                        'match_score' => count($matched),
                        'rationale' => $rationale,
                        'teaser_version' => $teaserVersion,
                        'sent_at' => now(),
                    ],
                );

                $this->audit->log('nomination.sent', $nomination, null, ['investor_user_id' => $investor->id, 'match_score' => count($matched)], $actor);
                $this->notifications->send($investor, new NominationSentNotification($nomination));

                $created->push($nomination);
            }

            return $created;
        });
    }
}
