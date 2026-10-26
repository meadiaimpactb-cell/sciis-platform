<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InterestStatus;
use App\Enums\OpportunityStatus;
use App\Exceptions\DomainException;
use App\Models\Interest;
use App\Models\InvestorProfile;
use App\Models\Membership;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\OpportunityStatusTransition;
use App\Models\TaxonomyTerm;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RPT-01 — every report is computed from the DB (no hard-coded targets).
 * Each report returns ['key', 'title', 'columns' => [...], 'rows' => [...], 'generated_at'] (table contract) plus a
 * chart contract for the web: 'kind' (bars|funnel|number|table), 'series' => [{label, value}], 'unit' ('%'|'days'|null).
 * Filters: from/to (period), sector/region (taxonomy key or id) — applied to opportunity-based reports.
 */
class ReportService
{
    public const P1_KEYS = [
        'registrations_by_type', 'memberships_by_status', 'opportunities_funnel', 'opportunities_by_sector_stage_region',
        'first_time_completion_rate', 'avg_review_time', 'avg_evaluation_time', 'overdue_items', 'eligibility_rate',
        'interest_rate', 'connection_rate', 'acquisition_channels',
    ];

    public const P2_KEYS = ['agreement_conversion', 'execution_status', 'impact_aggregates', 'meeting_attendance'];

    /** Reports whose rows are opportunity-based and therefore honour the sector/region filters. */
    public const OPPORTUNITY_SCOPED = [
        'opportunities_funnel', 'opportunities_by_sector_stage_region', 'first_time_completion_rate', 'avg_review_time',
        'avg_evaluation_time', 'overdue_items', 'eligibility_rate', 'interest_rate', 'connection_rate',
    ];

    /** Cumulative funnel stage → statuses counted in it (shared by the chart and the RPT-03 drill-down). */
    public const FUNNEL_STAGES = [
        'submitted' => null, // every submitted (non-draft) status
        'under_review' => ['under_review', 'eligible', 'connected', 'negotiating', 'in_execution', 'operating'],
        'eligible' => ['eligible', 'connected', 'negotiating', 'in_execution', 'operating'],
        'connected' => ['connected', 'negotiating', 'in_execution', 'operating'],
        'in_execution' => ['in_execution', 'operating'],
    ];

    /** RPT-03 — reports whose bars open a record list, and the dataset that list comes from. */
    public const DRILL = [
        'registrations_by_type' => 'accounts',
        'memberships_by_status' => 'memberships',
        'opportunities_funnel' => 'opportunities',
        'opportunities_by_sector_stage_region' => 'opportunities',
        'acquisition_channels' => 'memberships',
        'overdue_items' => 'opportunities',
    ];

    private ?int $sectorId = null;

    private ?int $regionId = null;

    /** @return array<string, mixed> */
    public function build(string $key, ?string $from = null, ?string $to = null, int|string|null $sector = null, int|string|null $region = null): array
    {
        if (! in_array($key, self::P1_KEYS, true)) {
            if (in_array($key, self::P2_KEYS, true)) {
                throw new DomainException(__('messages.reports.phase2'), 'report_not_available', 404);
            }
            throw new DomainException(__('messages.reports.unknown'), 'report_unknown', 404);
        }

        $this->sectorId = $this->termId('sectors', $sector);
        $this->regionId = $this->termId('regions', $region);

        $method = lcfirst(str_replace('_', '', ucwords($key, '_')));
        $result = $this->{$method}($from, $to);
        $chart = $this->chart($key, $result['columns'], $result['rows']);

        return [
            'key' => $key,
            'title' => __('reports.'.$key),
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'kind' => $chart['kind'],
            'series' => $chart['series'],
            'unit' => $chart['unit'],
            'period' => ['from' => $from, 'to' => $to],
            'filters' => ['sector' => $this->sectorId, 'region' => $this->regionId, 'supports_sector_region' => in_array($key, self::OPPORTUNITY_SCOPED, true)],
            'drill' => self::DRILL[$key] ?? null,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ---------------------------------------------------------------- chart contract

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{kind: string, series: array<int, array{label: string, value: float|int}>, unit: string|null}
     */
    private function chart(string $key, array $columns, array $rows): array
    {
        // RPT-03 — a bar whose label is a display text (sector, channel) also carries `key`: the raw segment value the
        // drill-down filters on. Elsewhere the label already is that value (`key` = label, omitted).
        $series = fn (string $labelCol, string $valueCol): array => array_values(array_map(fn (array $r): array => ['label' => (string) ($r[$labelCol] ?? ''), 'value' => (float) ($r[$valueCol] ?? 0)] + (isset($r['key']) ? ['key' => (string) $r['key']] : []), $rows));

        return match ($key) {
            'registrations_by_type' => ['kind' => 'bars', 'series' => $series('type', 'total'), 'unit' => null],
            'memberships_by_status' => ['kind' => 'bars', 'series' => $series('status', 'total'), 'unit' => null],
            'opportunities_funnel' => ['kind' => 'funnel', 'series' => $this->funnelSeries($rows), 'unit' => null],
            'opportunities_by_sector_stage_region' => ['kind' => 'bars', 'series' => array_values(array_map(fn (array $r): array => ['label' => (string) $r['value'], 'value' => (float) $r['total'], 'key' => (string) ($r['key'] ?? '')], array_filter($rows, fn (array $r): bool => $r['dimension'] === 'sector'))), 'unit' => null],
            'acquisition_channels' => ['kind' => 'bars', 'series' => $series('channel', 'total'), 'unit' => null],
            'overdue_items' => ['kind' => 'bars', 'series' => collect($rows)->countBy('kind')->map(fn (int $n, string $k): array => ['label' => $k, 'value' => $n])->values()->all(), 'unit' => null],
            'avg_review_time', 'avg_evaluation_time' => ['kind' => 'number', 'series' => $this->metricSeries($rows, 'avg_days'), 'unit' => 'days'],
            'first_time_completion_rate', 'eligibility_rate', 'interest_rate', 'connection_rate' => ['kind' => 'number', 'series' => $this->metricSeries($rows, 'rate_percent'), 'unit' => '%'],
            default => ['kind' => 'table', 'series' => [], 'unit' => null],
        };
    }

    /** Headline metric first, remaining metrics after it. @param array<int, array<string, mixed>> $rows */
    private function metricSeries(array $rows, string $headline): array
    {
        $byMetric = collect($rows)->keyBy('metric');
        $first = $byMetric->get($headline);
        $out = $first ? [['label' => $headline, 'value' => (float) ($first['value'] ?? 0)]] : [];
        foreach ($byMetric->except([$headline]) as $metric => $row) {
            $out[] = ['label' => (string) $metric, 'value' => (float) ($row['value'] ?? 0)];
        }

        return $out;
    }

    /** Cumulative funnel from the per-status counts: submitted → under_review+ → eligible+ → connected+ → in_execution+. */
    private function funnelSeries(array $rows): array
    {
        $counts = collect($rows)->pluck('total', 'status');
        $sum = fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $s): int => (int) ($counts[$s] ?? 0));

        return array_map(
            fn (string $stage, ?array $statuses): array => ['label' => $stage, 'value' => $sum($statuses ?? array_keys($counts->all()))],
            array_keys(self::FUNNEL_STAGES),
            self::FUNNEL_STAGES,
        );
    }

    // ---------------------------------------------------------------- filters

    private function termId(string $group, int|string|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return TaxonomyTerm::query()->where('group', $group)->where('key', (string) $value)->value('id');
    }

    /** Sector/region constraints on an Opportunity query. */
    private function scopeOpportunities(Builder $query): Builder
    {
        return $query
            ->when($this->sectorId !== null, fn (Builder $q) => $q->where('sector_id', $this->sectorId))
            ->when($this->regionId !== null, fn (Builder $q) => $q->whereHas('regions', fn (Builder $r) => $r->where('taxonomy_terms.id', $this->regionId)));
    }

    /** Same constraints through an `opportunity` relation (transitions, interests, nominations). */
    private function scopeViaOpportunity(Builder $query): Builder
    {
        if ($this->sectorId === null && $this->regionId === null) {
            return $query;
        }

        return $query->whereHas('opportunity', fn (Builder $o) => $this->scopeOpportunities($o));
    }

    // ---------------------------------------------------------------- reports

    /** @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>} */
    private function registrationsByType(?string $from, ?string $to): array
    {
        $rows = User::query()->whereNull('anonymized_at')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->select('type', DB::raw('count(*) as total'))
            ->groupBy('type')->get()
            ->map(fn ($r): array => ['type' => $r->type->value, 'label' => $r->type->label(), 'total' => (int) $r->total])->all();

        return ['columns' => ['type', 'label', 'total'], 'rows' => $rows];
    }

    private function membershipsByStatus(?string $from, ?string $to): array
    {
        $rows = Membership::query()
            ->when($from, fn ($q) => $q->where('updated_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('updated_at', '<=', $to))
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->get()
            ->map(fn ($r): array => ['status' => $r->status->value, 'label' => $r->status->label(), 'total' => (int) $r->total])->all();

        return ['columns' => ['status', 'label', 'total'], 'rows' => $rows];
    }

    private function opportunitiesFunnel(?string $from, ?string $to): array
    {
        $counts = $this->scopeOpportunities(Opportunity::query())->whereNotNull('submitted_at')
            ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('submitted_at', '<=', $to))
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        $rows = [];
        foreach (OpportunityStatus::cases() as $status) {
            if ($status === OpportunityStatus::Draft) {
                continue;
            }
            $rows[] = ['status' => $status->value, 'label' => $status->label(), 'total' => (int) ($counts[$status->value] ?? 0)];
        }

        return ['columns' => ['status', 'label', 'total'], 'rows' => $rows];
    }

    private function opportunitiesBySectorStageRegion(?string $from, ?string $to): array
    {
        $base = $this->scopeOpportunities(Opportunity::query())->whereNotNull('submitted_at')
            ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('submitted_at', '<=', $to));

        // `key` (sector term key, `none` = no sector) is not an export column — it feeds the RPT-03 drill-down.
        $bySector = (clone $base)->with('sector')->get()->groupBy(fn (Opportunity $o): string => $o->sector?->key ?? 'none')
            ->map(fn (Collection $g, $k): array => ['dimension' => 'sector', 'value' => $g->first()->sector?->label() ?? '—', 'total' => $g->count(), 'key' => (string) $k])->values();
        $byStage = (clone $base)->get()->groupBy(fn (Opportunity $o): string => $o->stage?->label() ?? '—')
            ->map(fn (Collection $g, $k): array => ['dimension' => 'stage', 'value' => (string) $k, 'total' => $g->count()])->values();
        $byRegion = (clone $base)->with('regions')->get()->flatMap(fn (Opportunity $o) => $o->regions->map(fn ($r) => $r->label()))
            ->countBy()->map(fn ($c, $k): array => ['dimension' => 'region', 'value' => (string) $k, 'total' => (int) $c])->values();

        return ['columns' => ['dimension', 'value', 'total'], 'rows' => $bySector->concat($byStage)->concat($byRegion)->all()];
    }

    /** Share of opportunities that went new → under_review without a needs_completion detour. */
    private function firstTimeCompletionRate(?string $from, ?string $to): array
    {
        $submitted = $this->scopeOpportunities(Opportunity::query())->whereNotNull('submitted_at')
            ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('submitted_at', '<=', $to))
            ->pluck('id');

        $total = $submitted->count();
        $needed = OpportunityStatusTransition::query()->whereIn('opportunity_id', $submitted)->where('to_status', OpportunityStatus::NeedsCompletion->value)->distinct('opportunity_id')->count('opportunity_id');
        $firstTime = max(0, $total - $needed);

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'rate_percent', 'value' => $total > 0 ? round($firstTime / $total * 100, 1) : 0],
            ['metric' => 'submitted', 'value' => $total],
            ['metric' => 'needed_completion', 'value' => $needed],
            ['metric' => 'first_time_complete', 'value' => $firstTime],
        ]];
    }

    /** Average days from submission (new) to first screening decision (under_review/needs_completion/not_eligible). */
    private function avgReviewTime(?string $from, ?string $to): array
    {
        return $this->avgTransitionDays(OpportunityStatus::New, [OpportunityStatus::UnderReview, OpportunityStatus::NeedsCompletion, OpportunityStatus::NotEligible], $from, $to);
    }

    /** Average days from under_review to eligible/not_eligible. */
    private function avgEvaluationTime(?string $from, ?string $to): array
    {
        return $this->avgTransitionDays(OpportunityStatus::UnderReview, [OpportunityStatus::Eligible, OpportunityStatus::NotEligible], $from, $to);
    }

    /** @param array<int, OpportunityStatus> $toStatuses */
    private function avgTransitionDays(OpportunityStatus $fromStatus, array $toStatuses, ?string $from, ?string $to): array
    {
        $starts = $this->scopeViaOpportunity(OpportunityStatusTransition::query())->where('to_status', $fromStatus->value)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderBy('created_at')->get()->groupBy('opportunity_id');

        $ends = OpportunityStatusTransition::query()->whereIn('to_status', array_map(fn ($s) => $s->value, $toStatuses))
            ->where('from_status', $fromStatus->value)->orderBy('created_at')->get()->groupBy('opportunity_id');

        $durations = [];
        foreach ($starts as $opportunityId => $startList) {
            $start = $startList->first()->created_at;
            $end = $ends->get($opportunityId)?->first(fn ($t) => $t->created_at->gte($start))?->created_at;
            if ($end) {
                $durations[] = abs((float) $start->diffInDays($end));
            }
        }

        $count = count($durations);

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'avg_days', 'value' => $count ? round(array_sum($durations) / $count, 1) : null],
            ['metric' => 'samples', 'value' => $count],
            ['metric' => 'max_days', 'value' => $count ? round(max($durations), 1) : null],
            ['metric' => 'sla_days', 'value' => app(SettingsService::class)->slaDays($fromStatus === OpportunityStatus::New ? 'review' : 'evaluation')],
        ]];
    }

    private function overdueItems(?string $from, ?string $to): array
    {
        // `uuid` is not an export column — it links the RPT-03 drill-down rows to the file.
        $rows = $this->scopeOpportunities(Opportunity::query())->overdue()->with(['assignedReviewer'])->get()->map(fn (Opportunity $o): array => [
            'uuid' => $o->uuid,
            'reference_no' => $o->reference_no,
            'name' => $o->name,
            'status' => $o->status->label(),
            'kind' => $o->overdueKind(),
            'due_at' => $o->slaDueAt()?->toDateString(),
            'assignee' => $o->assignedReviewer?->name,
        ])->all();

        $overdueInterests = $this->scopeViaOpportunity(Interest::query())->where('status', InterestStatus::AwaitingOwnerApproval->value)->where('owner_decision_due_at', '<', now())->with('opportunity')->get()
            ->map(fn (Interest $i): array => ['uuid' => $i->opportunity->uuid, 'reference_no' => $i->opportunity->reference_no, 'name' => $i->opportunity->name, 'status' => $i->status->label(), 'kind' => 'owner_decision', 'due_at' => $i->owner_decision_due_at?->toDateString(), 'assignee' => null])->all();

        return ['columns' => ['reference_no', 'name', 'status', 'kind', 'due_at', 'assignee'], 'rows' => array_merge($rows, $overdueInterests)];
    }

    private function eligibilityRate(?string $from, ?string $to): array
    {
        $decided = $this->scopeViaOpportunity(OpportunityStatusTransition::query())->whereIn('to_status', [OpportunityStatus::Eligible->value, OpportunityStatus::NotEligible->value])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->select('to_status', DB::raw('count(distinct opportunity_id) as total'))->groupBy('to_status')->pluck('total', 'to_status');

        $eligible = (int) ($decided[OpportunityStatus::Eligible->value] ?? 0);
        $not = (int) ($decided[OpportunityStatus::NotEligible->value] ?? 0);

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'rate_percent', 'value' => ($eligible + $not) > 0 ? round($eligible / ($eligible + $not) * 100, 1) : 0],
            ['metric' => 'eligible', 'value' => $eligible],
            ['metric' => 'not_eligible', 'value' => $not],
        ]];
    }

    /** Nominations → interest conversion. */
    private function interestRate(?string $from, ?string $to): array
    {
        $nominations = $this->scopeViaOpportunity(Nomination::query())->when($from, fn ($q) => $q->where('sent_at', '>=', $from))->when($to, fn ($q) => $q->where('sent_at', '<=', $to));
        $sent = (clone $nominations)->count();
        $interested = (clone $nominations)->where('response', 'interested')->count();
        $declined = (clone $nominations)->where('response', 'declined')->count();
        $interests = $this->scopeViaOpportunity(Interest::query())->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to))->count();

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'rate_percent', 'value' => $sent > 0 ? round($interested / $sent * 100, 1) : 0],
            ['metric' => 'nominations_sent', 'value' => $sent],
            ['metric' => 'nominations_interested', 'value' => $interested],
            ['metric' => 'nominations_declined', 'value' => $declined],
            ['metric' => 'interests_total', 'value' => $interests],
        ]];
    }

    /** Interests → connected conversion. */
    private function connectionRate(?string $from, ?string $to): array
    {
        $base = $this->scopeViaOpportunity(Interest::query())->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to));
        $total = (clone $base)->count();
        $approved = (clone $base)->where('owner_decision', 'approved')->count();
        $connected = (clone $base)->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value])->count();
        $closed = (clone $base)->where('status', InterestStatus::Closed->value)->count();

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'rate_percent', 'value' => $total > 0 ? round($connected / $total * 100, 1) : 0],
            ['metric' => 'interests', 'value' => $total],
            ['metric' => 'owner_approved', 'value' => $approved],
            ['metric' => 'connected', 'value' => $connected],
            ['metric' => 'closed', 'value' => $closed],
        ]];
    }

    private function acquisitionChannels(?string $from, ?string $to): array
    {
        $rows = InvestorProfile::query()->with('howHeard')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get()->groupBy(fn (InvestorProfile $p): string => $p->howHeard?->key ?? 'none')
            ->map(fn (Collection $g, $k): array => ['channel' => $g->first()->howHeard?->label() ?? '—', 'total' => $g->count(), 'key' => (string) $k])->values()->all();

        return ['columns' => ['channel', 'total'], 'rows' => $rows];
    }
}
