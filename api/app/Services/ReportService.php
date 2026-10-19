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
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RPT-01 — every report is computed from the DB (no hard-coded targets).
 * Each report returns ['key', 'title', 'columns' => [...], 'rows' => [...], 'generated_at'].
 */
class ReportService
{
    public const P1_KEYS = [
        'registrations_by_type', 'memberships_by_status', 'opportunities_funnel', 'opportunities_by_sector_stage_region',
        'first_time_completion_rate', 'avg_review_time', 'avg_evaluation_time', 'overdue_items', 'eligibility_rate',
        'interest_rate', 'connection_rate', 'acquisition_channels',
    ];

    public const P2_KEYS = ['agreement_conversion', 'execution_status', 'impact_aggregates', 'meeting_attendance'];

    /** @return array<string, mixed> */
    public function build(string $key, ?string $from = null, ?string $to = null): array
    {
        if (! in_array($key, self::P1_KEYS, true)) {
            if (in_array($key, self::P2_KEYS, true)) {
                throw new DomainException(__('messages.reports.phase2'), 'report_not_available', 404);
            }
            throw new DomainException(__('messages.reports.unknown'), 'report_unknown', 404);
        }

        $method = lcfirst(str_replace('_', '', ucwords($key, '_')));
        $result = $this->{$method}($from, $to);

        return [
            'key' => $key,
            'title' => __('reports.'.$key),
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'period' => ['from' => $from, 'to' => $to],
            'generated_at' => now()->toIso8601String(),
        ];
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
        $counts = Opportunity::query()->whereNotNull('submitted_at')
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
        $base = Opportunity::query()->whereNotNull('submitted_at')
            ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('submitted_at', '<=', $to));

        $bySector = (clone $base)->with('sector')->get()->groupBy(fn (Opportunity $o): string => $o->sector?->label() ?? '—')
            ->map(fn (Collection $g, $k): array => ['dimension' => 'sector', 'value' => (string) $k, 'total' => $g->count()])->values();
        $byStage = (clone $base)->get()->groupBy(fn (Opportunity $o): string => $o->stage?->label() ?? '—')
            ->map(fn (Collection $g, $k): array => ['dimension' => 'stage', 'value' => (string) $k, 'total' => $g->count()])->values();
        $byRegion = (clone $base)->with('regions')->get()->flatMap(fn (Opportunity $o) => $o->regions->map(fn ($r) => $r->label()))
            ->countBy()->map(fn ($c, $k): array => ['dimension' => 'region', 'value' => (string) $k, 'total' => (int) $c])->values();

        return ['columns' => ['dimension', 'value', 'total'], 'rows' => $bySector->concat($byStage)->concat($byRegion)->all()];
    }

    /** Share of opportunities that went new → under_review without a needs_completion detour. */
    private function firstTimeCompletionRate(?string $from, ?string $to): array
    {
        $submitted = Opportunity::query()->whereNotNull('submitted_at')
            ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('submitted_at', '<=', $to))
            ->pluck('id');

        $total = $submitted->count();
        $needed = OpportunityStatusTransition::query()->whereIn('opportunity_id', $submitted)->where('to_status', OpportunityStatus::NeedsCompletion->value)->distinct('opportunity_id')->count('opportunity_id');
        $firstTime = max(0, $total - $needed);

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'submitted', 'value' => $total],
            ['metric' => 'needed_completion', 'value' => $needed],
            ['metric' => 'first_time_complete', 'value' => $firstTime],
            ['metric' => 'rate_percent', 'value' => $total > 0 ? round($firstTime / $total * 100, 1) : 0],
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
        $starts = OpportunityStatusTransition::query()->where('to_status', $fromStatus->value)
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
            ['metric' => 'samples', 'value' => $count],
            ['metric' => 'avg_days', 'value' => $count ? round(array_sum($durations) / $count, 1) : null],
            ['metric' => 'max_days', 'value' => $count ? round(max($durations), 1) : null],
            ['metric' => 'sla_days', 'value' => app(SettingsService::class)->slaDays($fromStatus === OpportunityStatus::New ? 'review' : 'evaluation')],
        ]];
    }

    private function overdueItems(?string $from, ?string $to): array
    {
        $rows = Opportunity::query()->overdue()->with(['assignedReviewer'])->get()->map(fn (Opportunity $o): array => [
            'reference_no' => $o->reference_no,
            'name' => $o->name,
            'status' => $o->status->label(),
            'kind' => $o->overdueKind(),
            'due_at' => match ($o->overdueKind()) {
                'completion' => $o->completion_due_at?->toDateString(),
                'evaluation' => $o->evaluation_due_at?->toDateString(),
                default => $o->review_due_at?->toDateString(),
            },
            'assignee' => $o->assignedReviewer?->name,
        ])->all();

        $overdueInterests = Interest::query()->where('status', InterestStatus::AwaitingOwnerApproval->value)->where('owner_decision_due_at', '<', now())->with('opportunity')->get()
            ->map(fn (Interest $i): array => ['reference_no' => $i->opportunity->reference_no, 'name' => $i->opportunity->name, 'status' => $i->status->label(), 'kind' => 'owner_decision', 'due_at' => $i->owner_decision_due_at?->toDateString(), 'assignee' => null])->all();

        return ['columns' => ['reference_no', 'name', 'status', 'kind', 'due_at', 'assignee'], 'rows' => array_merge($rows, $overdueInterests)];
    }

    private function eligibilityRate(?string $from, ?string $to): array
    {
        $decided = OpportunityStatusTransition::query()->whereIn('to_status', [OpportunityStatus::Eligible->value, OpportunityStatus::NotEligible->value])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->select('to_status', DB::raw('count(distinct opportunity_id) as total'))->groupBy('to_status')->pluck('total', 'to_status');

        $eligible = (int) ($decided[OpportunityStatus::Eligible->value] ?? 0);
        $not = (int) ($decided[OpportunityStatus::NotEligible->value] ?? 0);

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'eligible', 'value' => $eligible],
            ['metric' => 'not_eligible', 'value' => $not],
            ['metric' => 'rate_percent', 'value' => ($eligible + $not) > 0 ? round($eligible / ($eligible + $not) * 100, 1) : 0],
        ]];
    }

    /** Nominations → interest conversion. */
    private function interestRate(?string $from, ?string $to): array
    {
        $nominations = Nomination::query()->when($from, fn ($q) => $q->where('sent_at', '>=', $from))->when($to, fn ($q) => $q->where('sent_at', '<=', $to));
        $sent = (clone $nominations)->count();
        $interested = (clone $nominations)->where('response', 'interested')->count();
        $declined = (clone $nominations)->where('response', 'declined')->count();
        $interests = Interest::query()->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to))->count();

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'nominations_sent', 'value' => $sent],
            ['metric' => 'nominations_interested', 'value' => $interested],
            ['metric' => 'nominations_declined', 'value' => $declined],
            ['metric' => 'interests_total', 'value' => $interests],
            ['metric' => 'rate_percent', 'value' => $sent > 0 ? round($interested / $sent * 100, 1) : 0],
        ]];
    }

    /** Interests → connected conversion. */
    private function connectionRate(?string $from, ?string $to): array
    {
        $base = Interest::query()->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to));
        $total = (clone $base)->count();
        $approved = (clone $base)->where('owner_decision', 'approved')->count();
        $connected = (clone $base)->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value])->count();
        $closed = (clone $base)->where('status', InterestStatus::Closed->value)->count();

        return ['columns' => ['metric', 'value'], 'rows' => [
            ['metric' => 'interests', 'value' => $total],
            ['metric' => 'owner_approved', 'value' => $approved],
            ['metric' => 'connected', 'value' => $connected],
            ['metric' => 'closed', 'value' => $closed],
            ['metric' => 'rate_percent', 'value' => $total > 0 ? round($connected / $total * 100, 1) : 0],
        ]];
    }

    private function acquisitionChannels(?string $from, ?string $to): array
    {
        $rows = InvestorProfile::query()->with('howHeard')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get()->groupBy(fn (InvestorProfile $p): string => $p->howHeard?->label() ?? '—')
            ->map(fn (Collection $g, $k): array => ['channel' => (string) $k, 'total' => $g->count()])->values()->all();

        return ['columns' => ['channel', 'total'], 'rows' => $rows];
    }
}
