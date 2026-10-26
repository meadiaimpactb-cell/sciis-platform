<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\NominationResponse;
use App\Exceptions\DomainException;
use App\Models\InvestorProfile;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Notifications\NominationSentNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MAT-01..04 — rule-based candidate ranking (no black box, MAT-02):
 * match_score = number of matched criteria among {sector, stage, region, investment_type, funding_range}.
 * Investors who declared a conflict of interest with the opportunity's owner/organization are excluded (MAT-04).
 *
 * MAT-06 — the ranking uses a transparent weighted fit (0-100, `weighted_score`) on top of the same rules:
 *  - each criterion the opportunity has data for weighs `matching.weight_*` points (sector/stage/region/type/range);
 *  - impact fit (`matching.weight_impact`): the opportunity's SDGs / beneficiary groups overlap the investor's impact
 *    interests — their declared causes (CAUSE_IMPACT) and the SDGs / groups of opportunities they saved, asked about
 *    or accepted before;
 *  - behaviour: + `matching.engagement_bonus` when they engaged with opportunities in the same sector before,
 *    - `matching.decline_penalty` per past decline whose reason concerns an attribute this opportunity shares
 *    (sector_fit → same sector, stage_fit → same stage, region → shared region, ticket_size → same range; max 2×).
 * Every candidate carries the breakdown and a human-readable `explanation`.
 */
class MatchingService
{
    public const CRITERIA = ['sector', 'stage', 'region', 'investment_type', 'funding_range'];

    /** MAT-06 — criterion → settings key of its weight. */
    public const WEIGHT_KEYS = [
        'sector' => 'matching.weight_sector',
        'stage' => 'matching.weight_stage',
        'region' => 'matching.weight_region',
        'investment_type' => 'matching.weight_type',
        'funding_range' => 'matching.weight_range',
        'impact' => 'matching.weight_impact',
    ];

    public const DEFAULT_WEIGHTS = ['sector' => 30, 'stage' => 15, 'region' => 15, 'investment_type' => 15, 'funding_range' => 10, 'impact' => 15];

    /** Investor causes (taxonomy `causes`) → the SDGs and beneficiary groups they stand for (impact fit, MAT-06). */
    public const CAUSE_IMPACT = [
        'poverty' => ['sdgs' => ['sdg_1', 'sdg_2', 'sdg_10'], 'beneficiaries' => ['low_income', 'orphans']],
        'education_access' => ['sdgs' => ['sdg_4'], 'beneficiaries' => ['children', 'youth']],
        'health_access' => ['sdgs' => ['sdg_3'], 'beneficiaries' => ['elderly', 'pwd']],
        'jobs' => ['sdgs' => ['sdg_8'], 'beneficiaries' => ['job_seekers', 'youth', 'smes']],
        'climate' => ['sdgs' => ['sdg_6', 'sdg_7', 'sdg_11', 'sdg_12', 'sdg_13', 'sdg_14', 'sdg_15'], 'beneficiaries' => ['rural', 'general']],
        'inclusion' => ['sdgs' => ['sdg_5', 'sdg_10'], 'beneficiaries' => ['women', 'pwd', 'elderly']],
    ];

    /** Decline reason key → the opportunity attribute it concerns. */
    private const DECLINE_REASON_ATTRIBUTE = ['sector_fit' => 'sector', 'stage_fit' => 'stage', 'region' => 'region', 'ticket_size' => 'funding_range'];

    /** @var array<string, array<int, int>>|null cause-mapped term ids, resolved once */
    private ?array $causeTermIds = null;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Returns approved investors ranked by weighted fit (desc), each with the matched criteria list.
     *
     * MAT-01 — `$filters` narrow the pool for a manual search (staff looking for an investor outside the ranked top
     * list): `search` (name / email), `sector`, `region`, `type` (investment type) — taxonomy key or id, comma list =
     * any of — and `investor_type` (individual|institutional). Eligibility (approved member, active) and the COI
     * exclusion always apply; nomination rules stay in nominate().
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{user: User, score: int, matched: array<int, string>, already_nominated: bool, weighted: int, breakdown: array<int, array<string, mixed>>, impact: array<int, string>, adjustments: array<int, array<string, mixed>>, explanation: string}>
     */
    public function candidates(Opportunity $opportunity, int $limit = 50, array $filters = []): Collection
    {
        $opportunity->loadMissing(['regions', 'investmentTypes', 'sector', 'fundingRange', 'owner', 'organization', 'sdgs', 'beneficiaryGroups']);

        $alreadyNominated = array_map('intval', $opportunity->nominations()->pluck('investor_user_id')->all());

        $search = trim((string) ($filters['search'] ?? ''));
        $investorType = match ((string) ($filters['investor_type'] ?? '')) {
            'individual', 'investor_individual' => 'investor_individual',
            'institutional', 'investor_institutional' => 'investor_institutional',
            default => null,
        };

        $query = InvestorProfile::query()
            ->with(['user', 'sectors', 'stages', 'investmentTypes', 'regions', 'investmentRange', 'causes'])
            ->whereHas('user', function ($q) use ($search, $investorType): void {
                $q->investors()->active()->whereHas('membership', fn ($m) => $m->where('status', MembershipStatus::Approved->value)->whereNotNull('terms_accepted_at'))
                    ->when($search !== '', fn ($s) => $s->where(fn ($w) => $w->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.mb_strtolower($search).'%')))
                    ->when($investorType !== null, fn ($t) => $t->where('type', $investorType));
            });

        foreach (['sector' => ['sectors', 'sectors'], 'region' => ['regions', 'regions'], 'type' => ['investmentTypes', 'investment_types']] as $key => [$relation, $group]) {
            $values = self::filterValues($filters[$key] ?? null);
            if ($values !== []) {
                $ids = self::termIds($group, $values);
                $query->whereHas($relation, fn ($t) => $t->whereIn('taxonomy_terms.id', $ids === [] ? [0] : $ids));
            }
        }

        $profiles = $query->get()->reject(fn (InvestorProfile $p): bool => $this->hasDeclaredConflict($p, $opportunity));
        $signals = $this->behaviourSignals($profiles->pluck('user_id')->map(fn ($id): int => (int) $id)->all(), (int) $opportunity->id);

        $ranked = $profiles
            ->map(function (InvestorProfile $p) use ($opportunity, $alreadyNominated, $signals): array {
                $fit = $this->assess($p, $opportunity, $signals[(int) $p->user_id] ?? []);

                return ['user' => $p->user, 'already_nominated' => in_array((int) $p->user_id, $alreadyNominated, true)] + $fit;
            })
            ->sortByDesc(fn (array $row): string => sprintf('%03d-%02d-%020d', $row['weighted'], $row['score'], PHP_INT_MAX - ($row['user']->id ?? 0)))
            ->values();

        return $ranked->take($limit);
    }

    /**
     * API shape of the ranked candidates (web `Candidate`): `investor` (alias of `user`) with id/uuid/name/type,
     * `matched_criteria` as [{key,label}] and `matched` as a boolean map keyed by the web's short names;
     * MAT-06 `weighted_score` (0-100), `score_breakdown`, `impact_matches` and `explanation`.
     *
     * @param  array<string, mixed>  $filters  see candidates()
     * @return array<int, array<string, mixed>>
     */
    public function candidateRows(Opportunity $opportunity, int $limit = 50, array $filters = []): array
    {
        return $this->candidates($opportunity, $limit, $filters)->map(function (array $row): array {
            $user = ['id' => $row['user']->id, 'uuid' => $row['user']->uuid, 'name' => $row['user']->name, 'type' => $row['user']->type->value, 'type_label' => $row['user']->type->label()];

            return [
                'user' => $user,
                'investor' => $user,
                'match_score' => $row['score'],
                'matched_criteria' => array_map(fn (string $c): array => ['key' => $c, 'label' => __('enums.match_criteria.'.$c)], $row['matched']),
                'matched' => self::matchedFlags($row['matched']),
                'already_nominated' => $row['already_nominated'],
                'weighted_score' => $row['weighted'],
                'score_breakdown' => $row['breakdown'],
                'adjustments' => $row['adjustments'],
                'impact_matches' => $row['impact'],
                'explanation' => $row['explanation'],
            ];
        })->values()->all();
    }

    // ---------------------------------------------------------------- MAT-06 weighted fit

    /** @return array<string, int> criterion → weight (points, ≥ 0) */
    public function weights(): array
    {
        $weights = [];
        foreach (self::WEIGHT_KEYS as $criterion => $key) {
            $weights[$criterion] = max(0, $this->settings->int($key, self::DEFAULT_WEIGHTS[$criterion]));
        }

        return $weights;
    }

    /**
     * Weighted fit of one investor with one opportunity. Expects the profile with sectors/stages/investmentTypes/
     * regions/causes and the opportunity with regions/investmentTypes/sdgs/beneficiaryGroups loaded (loads them otherwise).
     *
     * @param  array{declines?: array<int, array<string, mixed>>, sector_ids?: array<int, int>, sdg_ids?: array<int, int>, beneficiary_ids?: array<int, int>}  $signals  behaviourSignals() row
     * @return array{score: int, matched: array<int, string>, weighted: int, breakdown: array<int, array<string, mixed>>, impact: array<int, string>, adjustments: array<int, array<string, mixed>>, explanation: string}
     */
    public function assess(InvestorProfile $profile, Opportunity $opportunity, array $signals = []): array
    {
        $profile->loadMissing(['sectors', 'stages', 'investmentTypes', 'regions', 'causes']);
        $opportunity->loadMissing(['regions', 'investmentTypes', 'sdgs', 'beneficiaryGroups']);

        $matched = $this->matchedCriteria($profile, $opportunity);
        $weights = $this->weights();

        $applicable = [
            'sector' => $opportunity->sector_id !== null,
            'stage' => $opportunity->stage !== null,
            'region' => $opportunity->regions->isNotEmpty(),
            'investment_type' => $opportunity->investmentTypes->isNotEmpty(),
            'funding_range' => $opportunity->funding_range_id !== null,
        ];

        $breakdown = [];
        $points = 0.0;
        $max = 0;
        foreach (self::CRITERIA as $criterion) {
            if (! $applicable[$criterion]) {
                continue;
            }
            $hit = in_array($criterion, $matched, true);
            $breakdown[] = ['key' => $criterion, 'label' => __('enums.match_criteria.'.$criterion), 'points' => $hit ? $weights[$criterion] : 0, 'max' => $weights[$criterion]];
            $points += $hit ? $weights[$criterion] : 0;
            $max += $weights[$criterion];
        }

        // Impact fit: half for a shared SDG, half for a shared beneficiary group (only the dimensions the opportunity has).
        [$sdgInterest, $beneficiaryInterest] = $this->impactInterests($profile, $signals);
        $sharedSdgs = $opportunity->sdgs->filter(fn (TaxonomyTerm $t): bool => in_array((int) $t->id, $sdgInterest, true));
        $sharedGroups = $opportunity->beneficiaryGroups->filter(fn (TaxonomyTerm $t): bool => in_array((int) $t->id, $beneficiaryInterest, true));
        $dimensions = (int) $opportunity->sdgs->isNotEmpty() + (int) $opportunity->beneficiaryGroups->isNotEmpty();
        $impactItems = [];
        if ($dimensions > 0) {
            $hits = (int) $sharedSdgs->isNotEmpty() + (int) $sharedGroups->isNotEmpty();
            $impactPoints = (int) round($weights['impact'] * $hits / $dimensions);
            $breakdown[] = ['key' => 'impact', 'label' => __('messages.matching.impact_label'), 'points' => $impactPoints, 'max' => $weights['impact']];
            $points += $impactPoints;
            $max += $weights['impact'];
            $impactItems = $sharedSdgs->merge($sharedGroups)->map(fn (TaxonomyTerm $t): string => $t->label())->values()->all();
        }

        $weighted = $max > 0 ? (int) round(100 * $points / $max) : 0;

        // Behaviour adjustments (percentage points).
        $adjustments = [];
        $bonus = max(0, $this->settings->int('matching.engagement_bonus', 5));
        if ($bonus > 0 && $opportunity->sector_id !== null && in_array((int) $opportunity->sector_id, $signals['sector_ids'] ?? [], true)) {
            $adjustments[] = ['key' => 'engagement', 'points' => $bonus, 'label' => __('messages.matching.explain.engagement', ['points' => $bonus])];
        }
        $penalty = max(0, $this->settings->int('matching.decline_penalty', 10));
        if ($penalty > 0) {
            $applied = 0;
            foreach ($signals['declines'] ?? [] as $decline) {
                if ($applied >= 2) {
                    break;
                }
                if ($this->declineConcerns($decline, $opportunity)) {
                    $adjustments[] = ['key' => 'decline', 'reason' => $decline['reason'], 'points' => -$penalty, 'label' => __('messages.matching.explain.decline', ['reason' => $decline['reason_label'], 'points' => $penalty])];
                    $applied++;
                }
            }
        }
        $weighted = max(0, min(100, $weighted + array_sum(array_column($adjustments, 'points'))));

        return [
            'score' => count($matched),
            'matched' => $matched,
            'weighted' => $weighted,
            'breakdown' => $breakdown,
            'impact' => $impactItems,
            'adjustments' => $adjustments,
            'explanation' => $this->explain($matched, $impactItems, $adjustments),
        ];
    }

    /** OPP-07 / MSG-03 — weighted fit of one investor with one opportunity (behaviour signals loaded here). @return array<string, mixed>|null null without a profile */
    public function fitFor(User $investor, Opportunity $opportunity): ?array
    {
        $profile = $investor->relationLoaded('investorProfile') ? $investor->investorProfile : $investor->investorProfile()->first();
        if ($profile === null) {
            return null;
        }

        return $this->assess($profile, $opportunity, $this->behaviourSignals([(int) $investor->id], (int) $opportunity->id)[(int) $investor->id] ?? []);
    }

    /**
     * Past behaviour per investor (bulk): declined nominations with a reason (+ the declined opportunity's attributes)
     * and the sectors / SDGs / beneficiary groups of opportunities they saved, expressed interest in or accepted.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array{declines: array<int, array<string, mixed>>, sector_ids: array<int, int>, sdg_ids: array<int, int>, beneficiary_ids: array<int, int>}>
     */
    public function behaviourSignals(array $userIds, ?int $excludeOpportunityId = null): array
    {
        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return [];
        }
        $signals = array_fill_keys($userIds, ['declines' => [], 'sector_ids' => [], 'sdg_ids' => [], 'beneficiary_ids' => []]);

        $declines = Nomination::query()
            ->with(['declineReason', 'opportunity:id,sector_id,stage,funding_range_id', 'opportunity.regions:taxonomy_terms.id'])
            ->whereIn('investor_user_id', $userIds)
            ->where('response', NominationResponse::Declined->value)
            ->whereNotNull('decline_reason_id')
            ->when($excludeOpportunityId !== null, fn ($q) => $q->where('opportunity_id', '!=', $excludeOpportunityId))
            ->orderByDesc('responded_at')
            ->get();
        foreach ($declines as $n) {
            if ($n->opportunity === null || $n->declineReason === null || ! isset(self::DECLINE_REASON_ATTRIBUTE[$n->declineReason->key])) {
                continue;
            }
            $signals[(int) $n->investor_user_id]['declines'][] = [
                'reason' => $n->declineReason->key,
                'reason_label' => $n->declineReason->label(),
                'sector_id' => $n->opportunity->sector_id !== null ? (int) $n->opportunity->sector_id : null,
                'stage' => $n->opportunity->stage?->value,
                'region_ids' => $n->opportunity->regions->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'funding_range_id' => $n->opportunity->funding_range_id !== null ? (int) $n->opportunity->funding_range_id : null,
            ];
        }

        // Engagement: saved, expressed interest, accepted a nomination.
        $engaged = DB::table('saved_opportunities')->whereIn('user_id', $userIds)->select('user_id', 'opportunity_id')
            ->unionAll(DB::table('interests')->whereIn('investor_user_id', $userIds)->select('investor_user_id as user_id', 'opportunity_id'))
            ->unionAll(DB::table('nominations')->whereIn('investor_user_id', $userIds)->where('response', NominationResponse::Interested->value)->select('investor_user_id as user_id', 'opportunity_id'))
            ->get()
            ->reject(fn ($r): bool => $excludeOpportunityId !== null && (int) $r->opportunity_id === $excludeOpportunityId);
        $opportunityIds = $engaged->pluck('opportunity_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($opportunityIds !== []) {
            $sectors = DB::table('opportunities')->whereIn('id', $opportunityIds)->whereNotNull('sector_id')->pluck('sector_id', 'id');
            $sdgs = DB::table('opportunity_sdgs')->whereIn('opportunity_id', $opportunityIds)->get(['opportunity_id', 'sdg_id'])->groupBy('opportunity_id');
            $groups = DB::table('opportunity_beneficiary_groups')->whereIn('opportunity_id', $opportunityIds)->get(['opportunity_id', 'beneficiary_group_id'])->groupBy('opportunity_id');
            foreach ($engaged as $row) {
                $uid = (int) $row->user_id;
                $oid = (int) $row->opportunity_id;
                if (isset($sectors[$oid])) {
                    $signals[$uid]['sector_ids'][] = (int) $sectors[$oid];
                }
                foreach ($sdgs[$oid] ?? [] as $s) {
                    $signals[$uid]['sdg_ids'][] = (int) $s->sdg_id;
                }
                foreach ($groups[$oid] ?? [] as $g) {
                    $signals[$uid]['beneficiary_ids'][] = (int) $g->beneficiary_group_id;
                }
            }
            foreach ($signals as $uid => $row) {
                foreach (['sector_ids', 'sdg_ids', 'beneficiary_ids'] as $k) {
                    $signals[$uid][$k] = array_values(array_unique($row[$k]));
                }
            }
        }

        return $signals;
    }

    /** @param array<string, mixed> $decline */
    private function declineConcerns(array $decline, Opportunity $opportunity): bool
    {
        return match (self::DECLINE_REASON_ATTRIBUTE[$decline['reason']] ?? null) {
            'sector' => $opportunity->sector_id !== null && (int) $opportunity->sector_id === $decline['sector_id'],
            'stage' => $opportunity->stage !== null && $opportunity->stage->value === $decline['stage'],
            'region' => $opportunity->regions->pluck('id')->map(fn ($id): int => (int) $id)->intersect($decline['region_ids'])->isNotEmpty(),
            'funding_range' => $opportunity->funding_range_id !== null && (int) $opportunity->funding_range_id === $decline['funding_range_id'],
            default => false,
        };
    }

    /**
     * The investor's impact interests as term ids: SDGs / beneficiary groups mapped from their causes plus those of
     * the opportunities they engaged with.
     *
     * @param  array<string, mixed>  $signals
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function impactInterests(InvestorProfile $profile, array $signals): array
    {
        $this->causeTermIds ??= TaxonomyTerm::query()->whereIn('group', ['sdgs', 'beneficiary_groups'])->get(['id', 'group', 'key'])
            ->groupBy('group')->map(fn ($terms) => $terms->pluck('id', 'key')->map(fn ($id): int => (int) $id)->all())->all();

        $sdgs = $signals['sdg_ids'] ?? [];
        $groups = $signals['beneficiary_ids'] ?? [];
        foreach ($profile->causes as $cause) {
            foreach (self::CAUSE_IMPACT[$cause->key]['sdgs'] ?? [] as $key) {
                if (isset($this->causeTermIds['sdgs'][$key])) {
                    $sdgs[] = $this->causeTermIds['sdgs'][$key];
                }
            }
            foreach (self::CAUSE_IMPACT[$cause->key]['beneficiaries'] ?? [] as $key) {
                if (isset($this->causeTermIds['beneficiary_groups'][$key])) {
                    $groups[] = $this->causeTermIds['beneficiary_groups'][$key];
                }
            }
        }

        return [array_values(array_unique($sdgs)), array_values(array_unique($groups))];
    }

    /**
     * @param  array<int, string>  $matched
     * @param  array<int, string>  $impactItems
     * @param  array<int, array<string, mixed>>  $adjustments
     */
    private function explain(array $matched, array $impactItems, array $adjustments): string
    {
        $list = __('messages.matching.explain.list_separator');
        $parts = [$matched !== []
            ? __('messages.matching.explain.matched', ['criteria' => implode($list, array_map(fn (string $c): string => __('enums.match_criteria.'.$c), $matched))])
            : __('messages.matching.explain.none')];
        if ($impactItems !== []) {
            $parts[] = __('messages.matching.explain.impact', ['items' => implode($list, $impactItems)]);
        }
        foreach ($adjustments as $adjustment) {
            $parts[] = (string) $adjustment['label'];
        }

        return implode(__('messages.matching.explain.separator'), $parts);
    }

    // ---------------------------------------------------------------- rules (MAT-02 / MAT-04)

    /** @return array<int, string> comma list / array → trimmed non-empty strings */
    private static function filterValues(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $parts = is_array($value) ? array_map('strval', $value) : explode(',', (string) $value);

        return array_values(array_filter(array_map('trim', $parts), fn (string $v): bool => $v !== ''));
    }

    /**
     * @param  array<int, string>  $values  taxonomy keys or numeric ids
     * @return array<int, int>
     */
    private static function termIds(string $group, array $values): array
    {
        $numeric = array_map('intval', array_filter($values, 'ctype_digit'));

        return TaxonomyTerm::query()->where('group', $group)
            ->where(fn ($w) => $w->whereIn('key', $values)->when($numeric !== [], fn ($n) => $n->orWhereIn('id', $numeric)))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @param array<int, string> $matched @return array<string, bool> keys: sector|stage|region|type|range */
    public static function matchedFlags(array $matched): array
    {
        return [
            'sector' => in_array('sector', $matched, true),
            'stage' => in_array('stage', $matched, true),
            'region' => in_array('region', $matched, true),
            'type' => in_array('investment_type', $matched, true),
            'range' => in_array('funding_range', $matched, true),
        ];
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
            mb_strtolower((string) ($opportunity->name_en ?? '')),
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
