<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OpportunityStatus;
use App\Models\Interest;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\OpportunityAlert;
use App\Models\SavedFilter;
use App\Models\SavedOpportunity;
use App\Models\User;
use App\Support\OpportunityFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * OPP-07 / MSG-03 — which newly listed opportunities match an investor.
 *
 * "Newly listed" = an `eligible` opportunity whose teaser became fully approved (council + owner, the moment it turns
 * visible to investors) at or after the window start. Per investor, only what `Opportunity::visibleTo()` lets them
 * see counts (so under `nomination_only` nothing reaches them this way); opportunities they were nominated to, already
 * saved / expressed interest in, or were already alerted about are skipped.
 * A match comes from a saved search (`notify` on, listed after the search was saved) or, through the profile switch,
 * from the MAT-06 weighted fit ≥ `matching.alert_min_score`.
 */
class OpportunityAlertService
{
    public function __construct(
        private readonly MatchingService $matching,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Opportunities listed at or after `$since`, keyed by id.
     *
     * @return Collection<int, Opportunity>
     */
    public function newlyListed(Carbon $since): Collection
    {
        return Opportunity::query()
            ->status(OpportunityStatus::Eligible)
            ->withApprovedTeaser()
            ->whereHas('teaser', fn ($t) => $t->where(fn ($w) => $w->where('approved_by_council_at', '>=', $since)->orWhere('approved_by_owner_at', '>=', $since)))
            ->with(['teaser', 'sector', 'regions', 'investmentTypes', 'sdgs', 'beneficiaryGroups', 'fundingRange', 'organization'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Opportunity $o): bool => ($this->listedAt($o)?->gte($since)) ?? false)
            ->keyBy('id');
    }

    /** The moment the opportunity became visible to investors: the later of the two teaser approvals. */
    public function listedAt(Opportunity $opportunity): ?Carbon
    {
        $teaser = $opportunity->teaser;
        if ($teaser === null || $teaser->approved_by_council_at === null || $teaser->approved_by_owner_at === null) {
            return null;
        }

        return $teaser->approved_by_council_at->max($teaser->approved_by_owner_at);
    }

    /**
     * Matches for one investor, each opportunity once (a saved search wins over the profile switch).
     *
     * `$forAlerts` = OPP-07: only searches with `notify` on (and the profile switch when it is on), only opportunities
     * listed after the search / switch was saved, never one already alerted. Otherwise (digest, MSG-03): every saved
     * search and the profile fit, regardless of the alert switches.
     *
     * @param  Collection<int, Opportunity>  $candidates  newlyListed()
     * @return array<int, array{opportunity: Opportunity, source: string, saved_filter_id: ?int, filter_name: ?string, score: ?int, explanation: ?string}>
     */
    public function matchesFor(User $investor, Collection $candidates, bool $forAlerts = true): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        $skip = array_merge(
            Nomination::query()->where('investor_user_id', $investor->id)->pluck('opportunity_id')->all(),
            SavedOpportunity::query()->where('user_id', $investor->id)->pluck('opportunity_id')->all(),
            Interest::query()->where('investor_user_id', $investor->id)->pluck('opportunity_id')->all(),
            $forAlerts ? OpportunityAlert::query()->where('user_id', $investor->id)->pluck('opportunity_id')->all() : [],
        );
        $ids = array_values(array_diff($candidates->keys()->map(fn ($id): int => (int) $id)->all(), array_map('intval', $skip)));
        if ($ids === []) {
            return [];
        }
        $visible = Opportunity::query()->visibleTo($investor)->whereIn('opportunities.id', $ids)->pluck('opportunities.id')->map(fn ($id): int => (int) $id)->all();
        if ($visible === []) {
            return [];
        }

        $filters = SavedFilter::query()->where('user_id', $investor->id)->when($forAlerts, fn ($q) => $q->where('notify', true))->orderBy('id')->get();
        $matches = [];

        foreach ($filters->where('kind', SavedFilter::KIND_FILTER) as $filter) {
            $pool = array_values(array_filter($visible, fn (int $id): bool => ! isset($matches[$id]) && (! $forAlerts || $this->listedAfter($candidates[$id], $filter->created_at))));
            foreach (OpportunityFilters::matchingIds((array) $filter->filters, $investor, $pool) as $id) {
                $matches[$id] = ['opportunity' => $candidates[$id], 'source' => OpportunityAlert::SOURCE_FILTER, 'saved_filter_id' => (int) $filter->id, 'filter_name' => (string) $filter->name, 'score' => null, 'explanation' => null];
            }
        }

        $switch = $filters->firstWhere('kind', SavedFilter::KIND_PROFILE);
        if (! $forAlerts || $switch !== null) {
            $profile = $investor->investorProfile()->with(['sectors', 'stages', 'investmentTypes', 'regions', 'causes'])->first();
            if ($profile !== null) {
                $threshold = max(1, min(100, $this->settings->int('matching.alert_min_score', 50)));
                $signals = $this->matching->behaviourSignals([(int) $investor->id])[(int) $investor->id] ?? [];
                foreach ($visible as $id) {
                    if (isset($matches[$id]) || ($forAlerts && ! $this->listedAfter($candidates[$id], $switch->created_at))) {
                        continue;
                    }
                    $fit = $this->matching->assess($profile, $candidates[$id], $signals);
                    if ($fit['weighted'] >= $threshold) {
                        $matches[$id] = ['opportunity' => $candidates[$id], 'source' => OpportunityAlert::SOURCE_PROFILE, 'saved_filter_id' => null, 'filter_name' => null, 'score' => $fit['weighted'], 'explanation' => $fit['explanation']];
                    }
                }
            }
        }

        ksort($matches);

        return array_values($matches);
    }

    /**
     * Notification payload rows (plain arrays: queued notifications, both names so the mail can follow the
     * recipient's locale).
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public static function items(array $matches): array
    {
        return array_map(fn (array $m): array => [
            'uuid' => $m['opportunity']->uuid,
            'reference_no' => $m['opportunity']->reference_no,
            'name' => $m['opportunity']->name,
            'name_en' => $m['opportunity']->name_en,
            'source' => $m['source'],
            'filter_name' => $m['filter_name'],
            'score' => $m['score'],
        ], $matches);
    }

    private function listedAfter(Opportunity $opportunity, ?Carbon $moment): bool
    {
        $listedAt = $this->listedAt($opportunity);

        return $listedAt !== null && ($moment === null || $listedAt->gte($moment));
    }
}
