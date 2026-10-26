<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ExecutionStatus;
use App\Enums\IndicatorValueStatus;
use App\Enums\InvestorUpdateStatus;
use App\Enums\MilestonePlanStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProgressReportStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FollowUp\ExecutionStatusRequest;
use App\Http\Requests\FollowUp\FollowUpReasonRequest;
use App\Http\Requests\FollowUp\FollowUpReviewRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\FollowUp;
use App\Models\IndicatorValue;
use App\Models\InvestorUpdate;
use App\Models\Milestone;
use App\Models\Opportunity;
use App\Models\ProgressReport;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\FollowUpPresenter;
use App\Services\FollowUpService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin "Follow-up & impact" module (`impact.verify`): projects in negotiation / execution / operation with their
 * execution status, overdue reports and pending verifications; decisions on the milestone plan (PRJ-01), progress
 * reports (PRJ-02), execution-status override (PRJ-03), indicator values (IMP-02) and held owner updates (ENT-10).
 * Every decision is audited and notified to the owner.
 */
class AdminFollowUpController extends Controller
{
    private const HISTORY_ACTIONS = ['follow_up.%', 'milestone.%', 'progress_report.%', 'indicator_value.%', 'investor_update.%'];

    public function __construct(
        private readonly FollowUpService $followUps,
        private readonly FollowUpPresenter $presenter,
        private readonly AuditLogger $audit,
        private readonly DocumentService $documents,
    ) {}

    /** GET /admin/follow-up — `search`, `execution` (on_track|delayed|stalled), `status`, `needs_action=1`. */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request);

        $opportunities = Opportunity::query()
            ->whereIn('status', FollowUpService::values(FollowUpService::followUpStatuses()))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $term)->orWhere('reference_no', 'like', $term));
            })
            ->latest('updated_at')
            ->limit(500)
            ->get();

        $rows = $opportunities->map(function (Opportunity $o): array {
            $followUp = $this->followUps->refreshIfStale($o);

            return $this->presenter->summary(FollowUpPresenter::load($o->refresh()), $followUp, FollowUpPresenter::STAFF);
        });

        $stats = [
            'total' => $rows->count(),
            'on_track' => $rows->where('execution_status', ExecutionStatus::OnTrack->value)->count(),
            'delayed' => $rows->where('execution_status', ExecutionStatus::Delayed->value)->count(),
            'stalled' => $rows->where('execution_status', ExecutionStatus::Stalled->value)->count(),
            'overdue_reports' => $rows->sum('reports_overdue'),
            'pending_verifications' => $rows->sum(fn (array $r): int => (int) ($r['pending']['total'] ?? 0)),
        ];

        $rank = [ExecutionStatus::Stalled->value => 0, ExecutionStatus::Delayed->value => 1, ExecutionStatus::OnTrack->value => 2];
        $filtered = $rows
            ->when($request->filled('execution'), fn ($c) => $c->where('execution_status', (string) $request->input('execution')))
            ->when($request->boolean('needs_action'), fn ($c) => $c->filter(fn (array $r): bool => ($r['pending']['total'] ?? 0) > 0 || $r['reports_overdue'] > 0))
            ->sortBy(fn (array $r): string => sprintf('%d-%05d', $rank[$r['execution_status']] ?? 3, 99999 - (int) ($r['pending']['total'] ?? 0)))
            ->values();

        return response()->json(['data' => $filtered->all(), 'meta' => ['stats' => $stats, 'total' => $filtered->count()]]);
    }

    /** GET /admin/follow-up/{uuid} — full file + decision history. */
    public function show(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->assertCan($request);
        $followUp = $this->followUpOf($opportunity);
        if ($this->followUps->inFollowUp($opportunity)) {
            $followUp = $this->followUps->refreshIfStale($opportunity);
        }

        return $this->detail($opportunity, $followUp);
    }

    /** POST /admin/follow-up/{uuid}/plan/review {decision: approve|changes_requested, note} — PRJ-01 */
    public function reviewPlan(FollowUpReviewRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->assertCan($request);
        $followUp = $this->activeFollowUp($opportunity);
        if ($followUp->plan_status !== MilestonePlanStatus::Submitted) {
            throw new DomainException(__('messages.follow_up.plan_not_submitted'), 'plan_not_submitted', 422);
        }

        $approve = $request->isPositive();
        $note = $request->validated('note');
        $followUp->forceFill([
            'plan_status' => $approve ? MilestonePlanStatus::Approved : MilestonePlanStatus::ChangesRequested,
            'plan_version' => $approve ? $followUp->plan_version + 1 : $followUp->plan_version,
            'plan_reviewed_at' => now(), 'plan_reviewed_by' => $request->user()->id, 'plan_review_note' => $note,
        ])->save();

        $this->audit->log($approve ? 'follow_up.plan_approved' : 'follow_up.plan_changes_requested', $followUp, ['plan_status' => 'submitted'], [
            'plan_status' => $followUp->plan_status->value, 'plan_version' => $followUp->plan_version, 'note' => $note,
        ], $request->user());
        $this->followUps->notifyOwner($opportunity, $approve ? 'plan_approved' : 'plan_changes_requested', ['note' => $note]);
        $this->followUps->refreshStatus($opportunity, $followUp, $request->user());

        return $this->detail($opportunity, $followUp);
    }

    /** POST /admin/follow-up/{uuid}/milestones/{milestone}/reopen {reason} — PRJ-01: the evidence did not hold. */
    public function reopenMilestone(FollowUpReasonRequest $request, Opportunity $opportunity, Milestone $milestone): JsonResponse
    {
        $this->assertCan($request);
        $followUp = $this->activeFollowUp($opportunity);
        $this->assertBelongs($milestone->opportunity_id, $opportunity);
        if (! $milestone->isDone()) {
            throw new DomainException(__('messages.follow_up.milestone_not_done'), 'milestone_not_done', 422);
        }

        $reason = (string) $request->validated('reason');
        $milestone->forceFill(['status' => MilestoneStatus::InProgress, 'completed_at' => null, 'completed_by' => null])->save();
        $this->audit->log('milestone.reopened', $milestone, ['status' => 'done'], ['status' => 'in_progress', 'reason' => $reason], $request->user());
        $this->followUps->notifyOwner($opportunity, 'milestone_reopened', ['title' => $milestone->title, 'note' => $reason]);
        $this->followUps->refreshStatus($opportunity, $followUp, $request->user());

        return $this->detail($opportunity, $followUp);
    }

    /** POST /admin/follow-up/{uuid}/reports/{report}/review {decision: accept|changes_requested, note} — PRJ-02 */
    public function reviewReport(FollowUpReviewRequest $request, Opportunity $opportunity, ProgressReport $report): JsonResponse
    {
        $this->assertCan($request);
        $followUp = $this->activeFollowUp($opportunity);
        $this->assertBelongs($report->opportunity_id, $opportunity);
        if ($report->status !== ProgressReportStatus::Submitted) {
            throw new DomainException(__('messages.follow_up.report_not_submitted'), 'report_not_submitted', 422);
        }

        $accept = $request->isPositive();
        $note = $request->validated('note');
        $report->forceFill([
            'status' => $accept ? ProgressReportStatus::Accepted : ProgressReportStatus::ChangesRequested,
            'review_note' => $note, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ])->save();

        $this->audit->log($accept ? 'progress_report.accepted' : 'progress_report.changes_requested', $report, ['status' => 'submitted'], ['status' => $report->status->value, 'note' => $note], $request->user());
        $this->followUps->notifyOwner($opportunity, $accept ? 'report_accepted' : 'report_changes_requested', [
            'period' => $report->period_start->toDateString().' — '.$report->period_end->toDateString(), 'note' => $note,
        ]);
        $this->followUps->refreshStatus($opportunity, $followUp, $request->user());

        return $this->detail($opportunity, $followUp);
    }

    /** POST /admin/follow-up/{uuid}/indicator-values/{value}/review {decision: verify|needs_clarification, note} — IMP-02 */
    public function reviewIndicatorValue(FollowUpReviewRequest $request, Opportunity $opportunity, IndicatorValue $value): JsonResponse
    {
        $this->assertCan($request);
        $followUp = $this->activeFollowUp($opportunity);
        $indicator = $value->indicator;
        $this->assertBelongs($indicator?->opportunity_id, $opportunity);
        if ($value->status !== IndicatorValueStatus::Entered) {
            throw new DomainException(__('messages.follow_up.value_not_pending'), 'value_not_pending', 422);
        }

        $verify = $request->isPositive();
        $note = $request->validated('note');
        $value->forceFill([
            'status' => $verify ? IndicatorValueStatus::Verified : IndicatorValueStatus::NeedsClarification,
            'review_note' => $note,
            'verified_by' => $verify ? $request->user()->id : null,
            'verified_at' => $verify ? now() : null,
        ])->save();

        $this->audit->log($verify ? 'indicator_value.verified' : 'indicator_value.needs_clarification', $value, ['status' => 'entered'], [
            'status' => $value->status->value, 'indicator' => $indicator->name, 'value' => (float) $value->value, 'note' => $note,
        ], $request->user());
        $this->followUps->notifyOwner($opportunity, $verify ? 'indicator_verified' : 'indicator_needs_clarification', ['indicator' => $indicator->name, 'note' => $note]);

        return $this->detail($opportunity, $followUp);
    }

    /** POST /admin/follow-up/{uuid}/updates/{update}/review {decision: publish|reject, note} — ENT-10 council review. */
    public function reviewUpdate(FollowUpReviewRequest $request, Opportunity $opportunity, InvestorUpdate $update): JsonResponse
    {
        $this->assertCan($request);
        $followUp = $this->activeFollowUp($opportunity);
        $this->assertBelongs($update->opportunity_id, $opportunity);
        if ($update->status !== InvestorUpdateStatus::PendingReview) {
            throw new DomainException(__('messages.follow_up.update_not_pending'), 'update_not_pending', 422);
        }

        $publish = $request->isPositive();
        $note = $request->validated('note');
        $update->forceFill([
            'status' => $publish ? InvestorUpdateStatus::Published : InvestorUpdateStatus::Rejected,
            'sent_at' => $publish ? now() : null,
            'review_note' => $note, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ])->save();

        $this->audit->log($publish ? 'investor_update.published' : 'investor_update.rejected', $update, ['status' => 'pending_review'], ['status' => $update->status->value, 'note' => $note], $request->user());
        $title = $update->title ?: __('notifications.follow_up.update_posted.untitled');
        $this->followUps->notifyOwner($opportunity, $publish ? 'update_published' : 'update_rejected', ['title' => $title, 'note' => $note]);
        if ($publish) {
            $this->followUps->notifyInvestors($opportunity, $update);
        }

        return $this->detail($opportunity, $followUp);
    }

    /** PUT /admin/follow-up/{uuid}/status {status|null, reason} — PRJ-03 override (null = back to the computed status). */
    public function overrideStatus(ExecutionStatusRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->assertCan($request);
        $followUp = $this->activeFollowUp($opportunity);
        $status = $request->validated('status');
        $reason = $request->validated('reason');
        $before = ['override' => $followUp->status_override?->value, 'reason' => $followUp->override_reason];

        $followUp->forceFill($status === null
            ? ['status_override' => null, 'override_reason' => null, 'override_by' => null, 'override_at' => null]
            : ['status_override' => ExecutionStatus::from($status), 'override_reason' => $reason, 'override_by' => $request->user()->id, 'override_at' => now()])->save();

        $this->audit->log($status === null ? 'follow_up.status_override_cleared' : 'follow_up.status_overridden', $followUp, $before, ['override' => $status, 'reason' => $reason], $request->user());
        $this->followUps->refreshStatus($opportunity, $followUp, $request->user());

        return $this->detail($opportunity, $followUp);
    }

    /** GET /admin/follow-up/documents/{uuid}/download-url — follow-up files only, access logged. */
    public function documentUrl(Request $request, Document $document): JsonResponse
    {
        $this->assertCan($request);
        if (! $this->followUps->documentVisibleTo($document, $request->user())) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }

        return $this->ok($this->documents->temporaryUrl($document, $request->user()));
    }

    // ---------------------------------------------------------------- helpers

    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('impact.verify')) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }
    }

    private function followUpOf(Opportunity $opportunity): FollowUp
    {
        if ($this->followUps->inFollowUp($opportunity)) {
            return $this->followUps->ensure($opportunity);
        }

        return FollowUp::query()->where('opportunity_id', $opportunity->id)->first()
            ?? throw new DomainException(__('messages.follow_up.not_in_follow_up'), 'not_in_follow_up', 404);
    }

    /** Decisions apply only while the project is followed up. */
    private function activeFollowUp(Opportunity $opportunity): FollowUp
    {
        if (! $this->followUps->inFollowUp($opportunity)) {
            throw new DomainException(__('messages.follow_up.not_in_follow_up'), 'not_in_follow_up', 422);
        }

        return $this->followUps->ensure($opportunity);
    }

    private function assertBelongs(?int $opportunityId, Opportunity $opportunity): void
    {
        if ((int) $opportunityId !== (int) $opportunity->id) {
            throw new DomainException(__('messages.errors.not_found'), 'not_found', 404);
        }
    }

    private function detail(Opportunity $opportunity, FollowUp $followUp): JsonResponse
    {
        $opportunity = FollowUpPresenter::load($opportunity->refresh());
        $data = $this->presenter->detail($opportunity, $followUp->refresh(), FollowUpPresenter::STAFF);
        $data['history'] = $this->history($opportunity, $followUp);

        return $this->ok($data);
    }

    /** AUD-01 — the decision trail of this project's follow-up (latest 50). @return array<int, array<string, mixed>> */
    private function history(Opportunity $opportunity, FollowUp $followUp): array
    {
        $indicatorValueIds = IndicatorValue::query()->whereIn('impact_indicator_id', $opportunity->indicators->pluck('id'))->pluck('id');
        $targets = [
            'opportunity' => [$opportunity->id],
            'follow_up' => [$followUp->id],
            'milestone' => $opportunity->milestones()->withoutGlobalScopes()->pluck('id')->all(),
            'progress_report' => $opportunity->progressReports->pluck('id')->all(),
            'indicator_value' => $indicatorValueIds->all(),
            'investor_update' => InvestorUpdate::query()->where('opportunity_id', $opportunity->id)->pluck('id')->all(),
        ];

        return AuditLog::query()
            ->with('user')
            ->where(function (Builder $q) use ($targets): void {
                foreach ($targets as $type => $ids) {
                    if ($ids !== []) {
                        $q->orWhere(fn (Builder $w) => $w->where('auditable_type', $type)->whereIn('auditable_id', $ids));
                    }
                }
            })
            ->where(function (Builder $q): void {
                foreach (self::HISTORY_ACTIONS as $pattern) {
                    $q->orWhere('action', 'like', $pattern);
                }
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user?->name,
                'after' => $log->after,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
