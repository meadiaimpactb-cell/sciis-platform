<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IndicatorValueStatus;
use App\Enums\InvestorUpdateStatus;
use App\Enums\ProgressReportStatus;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\FollowUp;
use App\Models\ImpactIndicator;
use App\Models\IndicatorValue;
use App\Models\InvestorUpdate;
use App\Models\Milestone;
use App\Models\Opportunity;
use App\Models\ProgressReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * PRJ / IMP / ENT-10 / INV-06 — follow-up payloads per audience:
 *  - staff: everything, incl. pending items, override author and escalation;
 *  - owner: everything about their own project (no investor identities — only a count);
 *  - investor: approved plan, accepted reports, verified indicator values only (IMP-02: nothing unverified is
 *    shown outside), published updates; no review notes, evidence files or internal fields.
 */
class FollowUpPresenter
{
    public const STAFF = 'staff';

    public const OWNER = 'owner';

    public const INVESTOR = 'investor';

    public function __construct(private readonly FollowUpService $followUps) {}

    /** Eager loads used by both shapes. */
    public static function load(Opportunity $opportunity): Opportunity
    {
        return $opportunity->load([
            'owner', 'sector',
            'milestones' => fn ($q) => $q->orderBy('sort')->orderBy('due_at')->orderBy('id'),
            'milestones.documents',
            'progressReports' => fn ($q) => $q->orderByDesc('period_start'),
            'progressReports.documents',
            'indicators' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('id'),
            'indicators.values' => fn ($q) => $q->orderBy('period_end')->orderBy('id'),
            'indicators.values.evidence',
        ]);
    }

    /** @return array<string, mixed> list row */
    public function summary(Opportunity $opportunity, ?FollowUp $followUp, string $audience): array
    {
        $milestones = $opportunity->milestones;
        $planVisible = $followUp !== null && ($audience !== self::INVESTOR || $followUp->plan_version > 0);
        $reports = $this->visibleReports($opportunity, $audience);
        $latest = $reports->first();
        $periods = $followUp ? $this->followUps->periods($opportunity, $followUp, $opportunity->progressReports) : [];
        $nextDue = collect($periods)->first(fn (array $p): bool => $p['report'] === null && $p['state'] !== 'overdue');
        $updates = $this->visibleUpdates($opportunity, $audience);
        $next = $milestones->first(fn (Milestone $m): bool => ! $m->isDone());
        $status = $opportunity->execution_status;

        $row = [
            'uuid' => $opportunity->uuid,
            'reference_no' => $opportunity->reference_no,
            'name' => $opportunity->name,
            'sector' => $opportunity->sector ? ['key' => $opportunity->sector->key, 'label_ar' => $opportunity->sector->label_ar, 'label_en' => $opportunity->sector->label_en] : null,
            'status' => $opportunity->status->value,
            'status_label' => $opportunity->status->label(),
            'in_follow_up' => $followUp !== null,
            'execution_status' => $status?->value,
            'execution_status_label' => $status?->label(),
            'status_overridden' => $followUp?->status_override !== null,
            'plan_status' => $followUp?->plan_status->value,
            'milestones' => $planVisible ? [
                'total' => $milestones->count(),
                'done' => $milestones->filter->isDone()->count(),
                'overdue' => $milestones->filter->isOverdue()->count(),
                'next' => $next ? ['id' => $next->id, 'title' => $next->title, 'due_at' => $next->due_at?->toDateString()] : null,
            ] : null,
            'latest_report' => $latest ? $this->reportSummary($latest) : null,
            'impact' => $this->impactSummary($opportunity, $audience),
            'latest_update' => ($u = $updates->first()) ? ['id' => $u->id, 'title' => $u->title, 'excerpt' => Str::limit($u->body, 160), 'sent_at' => $u->sent_at?->toIso8601String()] : null,
        ];

        if ($audience !== self::INVESTOR) {
            $row['reports_overdue'] = collect($periods)->where('state', 'overdue')->count();
            $row['next_report_due_on'] = $nextDue ? $nextDue['due_on']->toDateString() : null;
            $row['owner'] = $audience === self::STAFF ? ['id' => $opportunity->owner?->id, 'name' => $opportunity->owner?->name] : null;
            $row['pending'] = $followUp ? $this->followUps->pendingCounts($opportunity, $followUp) : null;
            $row['escalated_at'] = $audience === self::STAFF ? $followUp?->escalated_at?->toIso8601String() : null;
        }

        return $row;
    }

    /** @return array<string, mixed> full follow-up file */
    public function detail(Opportunity $opportunity, ?FollowUp $followUp, string $audience): array
    {
        $isInvestor = $audience === self::INVESTOR;
        $planVisible = $followUp !== null && (! $isInvestor || $followUp->plan_version > 0);
        $data = $this->summary($opportunity, $followUp, $audience);

        $data['follow_up'] = $followUp === null ? null : array_filter([
            'plan_status' => $followUp->plan_status->value,
            'plan_status_label' => $followUp->plan_status->label(),
            'plan_version' => $followUp->plan_version,
            'plan_submitted_at' => $followUp->plan_submitted_at?->toIso8601String(),
            'plan_reviewed_at' => $followUp->plan_reviewed_at?->toIso8601String(),
            'plan_review_note' => $isInvestor ? null : $followUp->plan_review_note,
            'computed_status' => $isInvestor ? null : $followUp->computed_status?->value,
            'status_override' => $isInvestor ? null : $followUp->status_override?->value,
            'override_reason' => $isInvestor ? null : $followUp->override_reason,
            'override_at' => $isInvestor ? null : $followUp->override_at?->toIso8601String(),
            'override_by' => $audience === self::STAFF ? $followUp->overrideAuthor?->name : null,
            'remediation_plan' => $isInvestor ? null : $followUp->remediation_plan,
            'remediation_updated_at' => $isInvestor ? null : $followUp->remediation_updated_at?->toIso8601String(),
            'escalated_at' => $audience === self::STAFF ? $followUp->escalated_at?->toIso8601String() : null,
            'execution_started_at' => $followUp->execution_started_at?->toIso8601String(),
            'report_frequency' => $followUp->execution_started_at ? $this->followUps->frequency($followUp)->value : null,
            'delay_days' => $isInvestor ? null : $this->followUps->worstDelayDays($opportunity, $followUp),
            'stalled_after_days' => $isInvestor ? null : $this->followUps->stalledAfterDays(),
        ], fn ($v): bool => $v !== null);

        $data['milestones_list'] = $planVisible ? $opportunity->milestones->map(fn (Milestone $m): array => $this->milestone($m, $audience))->values()->all() : [];
        $data['budget_share_total'] = $planVisible ? round((float) $opportunity->milestones->sum(fn (Milestone $m): float => (float) $m->budget_share), 2) : null;
        $data['reports'] = $this->visibleReports($opportunity, $audience)->map(fn (ProgressReport $r): array => $this->report($r, $opportunity, $audience))->values()->all();
        $data['indicators'] = $opportunity->indicators->map(fn (ImpactIndicator $i): array => $this->indicator($i, $audience))->values()->all();
        $data['updates'] = $this->visibleUpdates($opportunity, $audience)->map(fn (InvestorUpdate $u): array => $this->update($u, $audience))->values()->all();

        if (! $isInvestor) {
            $data['periods'] = $followUp ? array_map(fn (array $p): array => [
                'period_start' => $p['start']->toDateString(),
                'period_end' => $p['end']->toDateString(),
                'due_on' => $p['due_on']->toDateString(),
                'state' => $p['state'],
                'report_id' => $p['report']?->id,
                'days_overdue' => $p['days_overdue'],
            ], array_reverse($this->followUps->periods($opportunity, $followUp, $opportunity->progressReports))) : [];
            $data['connected_investors'] = $this->followUps->connectedInvestors($opportunity)->count();
            $data['updates_require_review'] = $this->followUps->updatesRequireReview();
        }

        return $data;
    }

    // ---------------------------------------------------------------- items

    /** @return array<string, mixed> */
    public function milestone(Milestone $m, string $audience): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'description' => $m->description,
            'deliverable' => $m->deliverable,
            'responsible' => $m->responsible,
            'budget_share' => $m->budget_share !== null ? (float) $m->budget_share : null,
            'sort' => $m->sort,
            'due_at' => $m->due_at?->toDateString(),
            'status' => $m->displayStatus()->value,
            'status_label' => $m->displayStatus()->label(),
            'is_overdue' => $m->isOverdue(),
            'completed_at' => $m->completed_at?->toIso8601String(),
            'evidence_note' => $audience === self::INVESTOR ? null : $m->evidence_note,
            'evidence' => $audience === self::INVESTOR ? [] : $this->documents($m->documents),
        ];
    }

    /** @return array<string, mixed> */
    public function report(ProgressReport $r, Opportunity $opportunity, string $audience): array
    {
        $titles = $opportunity->milestones->pluck('title', 'id');

        return [
            'id' => $r->id,
            'period_start' => $r->period_start->toDateString(),
            'period_end' => $r->period_end->toDateString(),
            'due_on' => $r->due_on?->toDateString(),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'narrative' => $r->narrative,
            'achieved' => $r->achieved,
            'delayed' => $r->delayed,
            'risks' => $r->risks,
            'support_needed' => $r->support_needed,
            'spend_amount' => $r->spend_amount !== null ? (float) $r->spend_amount : null,
            'execution_status' => $r->execution_status?->value,
            'milestone_updates' => collect((array) $r->milestone_updates)->map(fn (array $u): array => $u + ['title' => $titles[$u['milestone_id'] ?? 0] ?? null])->values()->all(),
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'review_note' => $audience === self::INVESTOR ? null : $r->review_note,
            'is_late' => $r->due_on !== null && $r->submitted_at !== null && $r->submitted_at->copy()->startOfDay()->gt($r->due_on),
            'attachments' => $this->documents($r->documents),
        ];
    }

    /** @return array<string, mixed> IMP-01 indicator carried forward from the registration + IMP-02 values */
    public function indicator(ImpactIndicator $i, string $audience): array
    {
        $values = $audience === self::INVESTOR ? $i->values->where('status', IndicatorValueStatus::Verified) : $i->values;
        $latest = $i->values->where('status', IndicatorValueStatus::Verified)->sortBy(fn (IndicatorValue $v): string => $v->period_end->toDateString().sprintf('%010d', $v->id))->last();
        $baseline = $i->baseline !== null ? (float) $i->baseline : null;
        $target = $i->target !== null ? (float) $i->target : null;

        return [
            'id' => $i->id,
            'name' => $i->name,
            'definition' => $i->definition,
            'unit' => $i->unit,
            'baseline' => $baseline,
            'target' => $target,
            'frequency' => $i->frequency?->value,
            'verification_method' => $i->verification_method,
            'is_primary' => $i->is_primary,
            'latest_verified' => $latest ? ['value' => (float) $latest->value, 'period_end' => $latest->period_end->toDateString()] : null,
            'progress_percent' => self::progress($baseline, $target, $latest ? (float) $latest->value : null),
            'values' => $values->values()->map(fn (IndicatorValue $v): array => [
                'id' => $v->id,
                'period_start' => $v->period_start->toDateString(),
                'period_end' => $v->period_end->toDateString(),
                'value' => (float) $v->value,
                'status' => $v->status->value,
                'status_label' => $v->status->label(),
                'note' => $v->note,
                'review_note' => $audience === self::INVESTOR ? null : $v->review_note,
                'verified_at' => $v->verified_at?->toIso8601String(),
                'created_at' => $v->created_at?->toIso8601String(),
                'evidence' => $audience === self::INVESTOR || ! $v->evidence ? null : (new DocumentResource($v->evidence))->resolve(request()),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function update(InvestorUpdate $u, string $audience): array
    {
        $u->loadMissing('documents');

        return [
            'id' => $u->id,
            'title' => $u->title,
            'body' => $u->body,
            'status' => $u->status->value,
            'status_label' => $u->status->label(),
            'metrics' => $u->metrics ?? [],
            'sent_at' => $u->sent_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
            'review_note' => $audience === self::INVESTOR ? null : $u->review_note,
            'attachments' => $this->documents($u->documents),
        ];
    }

    /** @return array<string, mixed> list-row digest of the latest report */
    public function reportSummary(ProgressReport $r): array
    {
        return [
            'id' => $r->id,
            'period_start' => $r->period_start->toDateString(),
            'period_end' => $r->period_end->toDateString(),
            'status' => $r->status->value,
            'excerpt' => Str::limit((string) ($r->narrative ?: $r->achieved), 200),
            'submitted_at' => $r->submitted_at?->toIso8601String(),
        ];
    }

    /** IMP-01 — progress from the baseline towards the target (%), null without a target or a verified value. */
    public static function progress(?float $baseline, ?float $target, ?float $value): ?float
    {
        if ($target === null || $value === null) {
            return null;
        }
        $base = $baseline ?? 0.0;
        if (abs($target - $base) < 0.000001) {
            return $value >= $target ? 100.0 : 0.0;
        }

        return round(($value - $base) / ($target - $base) * 100, 1);
    }

    // ---------------------------------------------------------------- helpers

    /** @return Collection<int, ProgressReport> newest first */
    private function visibleReports(Opportunity $opportunity, string $audience): Collection
    {
        $reports = $opportunity->progressReports->sortByDesc(fn (ProgressReport $r): string => $r->period_start->toDateString())->values();

        return $audience === self::INVESTOR ? $reports->where('status', ProgressReportStatus::Accepted)->values() : $reports;
    }

    /** @return Collection<int, InvestorUpdate> newest first */
    private function visibleUpdates(Opportunity $opportunity, string $audience): Collection
    {
        return InvestorUpdate::query()
            ->with('documents')
            ->where('opportunity_id', $opportunity->id)
            ->when($audience === self::INVESTOR, fn ($q) => $q->where('status', InvestorUpdateStatus::Published->value))
            ->orderByDesc('id')
            ->get();
    }

    /** @return array<string, mixed>|null */
    private function impactSummary(Opportunity $opportunity, string $audience): ?array
    {
        $indicators = $opportunity->indicators;
        if ($indicators->isEmpty()) {
            return null;
        }
        $primary = $indicators->firstWhere('is_primary', true) ?? $indicators->first();
        $shape = $this->indicator($primary, $audience);

        return [
            'indicators' => $indicators->count(),
            'verified_values' => $indicators->sum(fn (ImpactIndicator $i): int => $i->values->where('status', IndicatorValueStatus::Verified)->count()),
            'primary' => ['name' => $shape['name'], 'unit' => $shape['unit'], 'baseline' => $shape['baseline'], 'target' => $shape['target'], 'latest_verified' => $shape['latest_verified'], 'progress_percent' => $shape['progress_percent']],
        ];
    }

    /** @param iterable<Document> $documents @return array<int, array<string, mixed>> */
    private function documents(iterable $documents): array
    {
        return collect($documents)->sortBy('id')->values()->map(fn (Document $d): array => (new DocumentResource($d))->resolve(request()))->all();
    }
}
