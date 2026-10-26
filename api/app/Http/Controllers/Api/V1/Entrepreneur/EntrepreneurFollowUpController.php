<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Entrepreneur;

use App\Enums\DocumentKind;
use App\Enums\IndicatorValueStatus;
use App\Enums\InvestorUpdateStatus;
use App\Enums\MilestonePlanStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProgressReportStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FollowUp\CompleteMilestoneRequest;
use App\Http\Requests\FollowUp\IndicatorValueRequest;
use App\Http\Requests\FollowUp\InvestorUpdateRequest;
use App\Http\Requests\FollowUp\MilestoneRequest;
use App\Http\Requests\FollowUp\ProgressReportRequest;
use App\Http\Requests\FollowUp\RemediationPlanRequest;
use App\Models\FollowUp;
use App\Models\ImpactIndicator;
use App\Models\IndicatorValue;
use App\Models\InvestorUpdate;
use App\Models\Milestone;
use App\Models\Opportunity;
use App\Models\ProgressReport;
use App\Services\AuditLogger;
use App\Services\FollowUpPresenter;
use App\Services\FollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Owner side of the post-connection follow-up:
 * PRJ-01 milestone plan (propose → submit → approved / changes requested; revise with re-approval; mark done with
 * evidence), PRJ-02 periodic progress reports (+ resubmission), PRJ-03 remediation plan, IMP-01/02 indicator values
 * per period with evidence, ENT-10 updates to the connected investors. Every write is audited.
 */
class EntrepreneurFollowUpController extends Controller
{
    public function __construct(
        private readonly FollowUpService $followUps,
        private readonly FollowUpPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /entrepreneur/follow-up — the owner's projects in negotiation / execution / operation. */
    public function index(Request $request): JsonResponse
    {
        $rows = $request->user()->opportunities()
            ->whereIn('status', FollowUpService::values(FollowUpService::followUpStatuses()))
            ->latest('updated_at')
            ->get()
            ->map(function (Opportunity $o): array {
                $followUp = $this->followUps->refreshIfStale($o);

                return $this->presenter->summary(FollowUpPresenter::load($o->refresh()), $followUp, FollowUpPresenter::OWNER);
            });

        return response()->json(['data' => $rows->values()->all()]);
    }

    /** GET /entrepreneur/follow-up/{uuid} */
    public function show(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->owned($request, $opportunity);

        return $this->detail($opportunity, $this->followUps->refreshIfStale($opportunity));
    }

    // ---------------------------------------------------------------- PRJ-01 plan

    /** POST /entrepreneur/follow-up/{uuid}/milestones */
    public function storeMilestone(MilestoneRequest $request, Opportunity $opportunity): JsonResponse
    {
        $followUp = $this->owned($request, $opportunity);
        $this->followUps->assertPlanEditable($followUp);
        $data = $request->validated();
        $this->assertBudget($opportunity, (float) ($data['budget_share'] ?? 0));

        $milestone = $opportunity->milestones()->create($data + [
            'sort' => $data['sort'] ?? ((int) $opportunity->milestones()->max('sort') + 1),
            'status' => MilestoneStatus::Planned,
        ]);
        $this->audit->log('milestone.created', $milestone, null, collect($milestone->getAttributes())->only(['title', 'deliverable', 'due_at', 'budget_share', 'responsible'])->all(), $request->user());

        return response()->json(['data' => $this->presenter->milestone($milestone->load('documents'), FollowUpPresenter::OWNER)], 201);
    }

    /** PATCH /entrepreneur/follow-up/{uuid}/milestones/{milestone} */
    public function updateMilestone(MilestoneRequest $request, Opportunity $opportunity, Milestone $milestone): JsonResponse
    {
        $followUp = $this->owned($request, $opportunity);
        $this->assertBelongs($milestone->opportunity_id, $opportunity);
        $this->followUps->assertPlanEditable($followUp);
        if ($milestone->isDone()) {
            throw new DomainException(__('messages.follow_up.milestone_done'), 'milestone_done', 422);
        }
        $data = $request->validated();
        if (array_key_exists('budget_share', $data)) {
            $this->assertBudget($opportunity, (float) ($data['budget_share'] ?? 0), $milestone->id);
        }

        $milestone->fill($data)->save();
        $this->audit->logModelChange('milestone.updated', $milestone, $request->user());

        return $this->ok($this->presenter->milestone($milestone->load('documents'), FollowUpPresenter::OWNER));
    }

    /** DELETE /entrepreneur/follow-up/{uuid}/milestones/{milestone} — only open milestones of an editable plan. */
    public function destroyMilestone(Request $request, Opportunity $opportunity, Milestone $milestone): JsonResponse
    {
        $followUp = $this->owned($request, $opportunity);
        $this->assertBelongs($milestone->opportunity_id, $opportunity);
        $this->followUps->assertPlanEditable($followUp);
        if ($milestone->isDone()) {
            throw new DomainException(__('messages.follow_up.milestone_done'), 'milestone_done', 422);
        }

        $milestone->delete();
        $this->audit->log('milestone.deleted', $milestone, ['title' => $milestone->title, 'due_at' => $milestone->due_at?->toDateString()], null, $request->user());

        return $this->message(__('messages.deleted'));
    }

    /** POST /entrepreneur/follow-up/{uuid}/plan/submit — PRJ-01: the plan goes to the council for approval. */
    public function submitPlan(Request $request, Opportunity $opportunity): JsonResponse
    {
        $followUp = $this->owned($request, $opportunity);
        $this->followUps->assertPlanEditable($followUp);
        if (! $opportunity->milestones()->exists()) {
            throw new DomainException(__('messages.follow_up.plan_empty'), 'plan_empty', 422);
        }

        $before = $followUp->plan_status->value;
        $followUp->forceFill(['plan_status' => MilestonePlanStatus::Submitted, 'plan_submitted_at' => now()])->save();
        $this->audit->log('follow_up.plan_submitted', $followUp, ['plan_status' => $before], ['plan_status' => 'submitted', 'milestones' => $opportunity->milestones()->count()], $request->user());
        $this->followUps->notifyStaff($opportunity, 'plan_submitted');

        return $this->detail($opportunity, $followUp);
    }

    /** POST /entrepreneur/follow-up/{uuid}/plan/revise — PRJ-01: reopen a submitted / approved plan; changes need a new approval. */
    public function revisePlan(Request $request, Opportunity $opportunity): JsonResponse
    {
        $followUp = $this->owned($request, $opportunity);
        if (! in_array($followUp->plan_status, [MilestonePlanStatus::Submitted, MilestonePlanStatus::Approved], true)) {
            throw new DomainException(__('messages.follow_up.plan_not_revisable'), 'plan_not_revisable', 422);
        }

        $before = $followUp->plan_status->value;
        $followUp->forceFill(['plan_status' => MilestonePlanStatus::Draft])->save();
        $this->audit->log('follow_up.plan_revision_started', $followUp, ['plan_status' => $before], ['plan_status' => 'draft'], $request->user());

        return $this->detail($opportunity, $followUp);
    }

    /** POST /entrepreneur/follow-up/{uuid}/milestones/{milestone}/complete — PRJ-01: done, with evidence (multipart). */
    public function completeMilestone(CompleteMilestoneRequest $request, Opportunity $opportunity, Milestone $milestone): JsonResponse
    {
        $followUp = $this->owned($request, $opportunity);
        $this->assertBelongs($milestone->opportunity_id, $opportunity);
        if ($followUp->plan_status !== MilestonePlanStatus::Approved) {
            throw new DomainException(__('messages.follow_up.plan_not_approved'), 'plan_not_approved', 422);
        }
        if ($milestone->isDone()) {
            throw new DomainException(__('messages.follow_up.milestone_done'), 'milestone_done', 422);
        }

        $this->followUps->attach($request->file('file'), $request->user(), $milestone, DocumentKind::ProgressEvidence);
        $before = ['status' => $milestone->status->value];
        $milestone->forceFill([
            'status' => MilestoneStatus::Done, 'completed_at' => now(), 'completed_by' => $request->user()->id,
            'evidence_note' => $request->validated('note'),
        ])->save();
        $this->audit->log('milestone.completed', $milestone, $before, ['status' => 'done', 'note' => $milestone->evidence_note], $request->user());
        $this->followUps->notifyStaff($opportunity, 'milestone_completed', ['title' => $milestone->title]);
        $this->followUps->refreshStatus($opportunity, $followUp, $request->user());

        return $this->ok($this->presenter->milestone($milestone->load('documents'), FollowUpPresenter::OWNER));
    }

    // ---------------------------------------------------------------- PRJ-02 reports

    /** POST /entrepreneur/follow-up/{uuid}/reports — for one of the open periods (multipart, up to 5 files). */
    public function storeReport(ProgressReportRequest $request, Opportunity $opportunity): JsonResponse
    {
        $followUp = $this->owned($request, $opportunity);
        if (! $this->followUps->isReporting($opportunity)) {
            throw new DomainException(__('messages.follow_up.reporting_not_started'), 'reporting_not_started', 422);
        }
        $data = $request->validated();
        $period = collect($this->followUps->openPeriods($opportunity, $followUp))->first(fn (array $p): bool => $p['start']->toDateString() === $data['period_start']);
        if ($period === null) {
            throw new DomainException(__('messages.follow_up.period_not_open'), 'validation_failed', 422, ['period_start' => [__('messages.follow_up.period_not_open')]]);
        }
        $updates = $this->milestoneUpdates($opportunity, (array) ($data['milestone_updates'] ?? []));

        $report = DB::transaction(function () use ($opportunity, $data, $period, $updates, $request): ProgressReport {
            $report = $opportunity->progressReports()->create($this->reportFields($data) + [
                'period_start' => $period['start']->toDateString(),
                'period_end' => $period['end']->toDateString(),
                'due_on' => $period['due_on']->toDateString(),
                'milestone_updates' => $updates,
                'status' => ProgressReportStatus::Submitted,
                'submitted_at' => now(),
                'submitted_by' => $request->user()->id,
            ]);
            $this->applyMilestoneUpdates($opportunity, $updates);

            return $report;
        });
        foreach ((array) $request->file('files', []) as $file) {
            $this->followUps->attach($file, $request->user(), $report, DocumentKind::ProgressEvidence);
        }

        $this->audit->log('progress_report.submitted', $report, null, ['period_start' => $report->period_start->toDateString(), 'period_end' => $report->period_end->toDateString(), 'late' => $period['state'] === 'overdue'], $request->user());
        $this->followUps->notifyStaff($opportunity, 'report_submitted', ['period' => $this->periodLabel($report)]);
        $this->followUps->refreshStatus($opportunity, $followUp, $request->user());

        return response()->json(['data' => $this->presenter->report($report->load('documents'), $opportunity->load('milestones'), FollowUpPresenter::OWNER)], 201);
    }

    /** POST /entrepreneur/follow-up/{uuid}/reports/{report}/resubmit — after the council asked for changes. */
    public function resubmitReport(ProgressReportRequest $request, Opportunity $opportunity, ProgressReport $report): JsonResponse
    {
        $followUp = $this->owned($request, $opportunity);
        $this->assertBelongs($report->opportunity_id, $opportunity);
        if ($report->status !== ProgressReportStatus::ChangesRequested) {
            throw new DomainException(__('messages.follow_up.report_not_editable'), 'report_not_editable', 422);
        }
        $data = $request->validated();
        $updates = $this->milestoneUpdates($opportunity, (array) ($data['milestone_updates'] ?? []));

        DB::transaction(function () use ($report, $data, $updates, $opportunity): void {
            $report->forceFill($this->reportFields($data) + ['milestone_updates' => $updates, 'status' => ProgressReportStatus::Submitted, 'submitted_at' => now()])->save();
            $this->applyMilestoneUpdates($opportunity, $updates);
        });
        foreach ((array) $request->file('files', []) as $file) {
            $this->followUps->attach($file, $request->user(), $report, DocumentKind::ProgressEvidence);
        }

        $this->audit->log('progress_report.resubmitted', $report, ['status' => 'changes_requested'], ['status' => 'submitted'], $request->user());
        $this->followUps->notifyStaff($opportunity, 'report_submitted', ['period' => $this->periodLabel($report)]);
        $this->followUps->refreshStatus($opportunity, $followUp, $request->user());

        return $this->ok($this->presenter->report($report->load('documents'), $opportunity->load('milestones'), FollowUpPresenter::OWNER));
    }

    // ---------------------------------------------------------------- IMP-01/02 indicator values

    /** POST /entrepreneur/follow-up/{uuid}/indicators/{indicator}/values (multipart: value, period, note, evidence file) */
    public function storeIndicatorValue(IndicatorValueRequest $request, Opportunity $opportunity, ImpactIndicator $indicator): JsonResponse
    {
        $this->owned($request, $opportunity);
        $this->assertBelongs($indicator->opportunity_id, $opportunity);
        $data = $request->validated();
        $duplicate = $indicator->values()
            ->whereDate('period_start', $data['period_start'])->whereDate('period_end', $data['period_end'])
            ->where('status', '!=', IndicatorValueStatus::NeedsClarification->value)
            ->exists();
        if ($duplicate) {
            throw new DomainException(__('messages.follow_up.value_exists'), 'validation_failed', 422, ['period_end' => [__('messages.follow_up.value_exists')]]);
        }

        $value = $indicator->values()->create([
            'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'value' => $data['value'],
            'note' => $data['note'] ?? null, 'status' => IndicatorValueStatus::Entered, 'entered_by' => $request->user()->id,
        ]);
        if ($request->hasFile('file')) {
            $document = $this->followUps->attach($request->file('file'), $request->user(), $value, DocumentKind::ImpactEvidence);
            $value->forceFill(['evidence_document_id' => $document->id])->save();
        }

        $this->audit->log('indicator_value.entered', $value, null, ['indicator' => $indicator->name, 'value' => (float) $value->value, 'period_end' => $data['period_end']], $request->user());
        $this->followUps->notifyStaff($opportunity, 'indicator_submitted', ['indicator' => $indicator->name]);

        return response()->json(['data' => $this->indicatorPayload($indicator)], 201);
    }

    /** POST /entrepreneur/follow-up/{uuid}/indicator-values/{value}/resubmit — answers a clarification request. */
    public function resubmitIndicatorValue(IndicatorValueRequest $request, Opportunity $opportunity, IndicatorValue $value): JsonResponse
    {
        $this->owned($request, $opportunity);
        $indicator = $value->indicator;
        $this->assertBelongs($indicator?->opportunity_id, $opportunity);
        if ($value->status !== IndicatorValueStatus::NeedsClarification) {
            throw new DomainException(__('messages.follow_up.value_not_editable'), 'value_not_editable', 422);
        }
        $data = $request->validated();
        $before = ['value' => (float) $value->value, 'status' => $value->status->value];

        $value->forceFill([
            'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'value' => $data['value'],
            'note' => $data['note'] ?? $value->note, 'status' => IndicatorValueStatus::Entered, 'entered_by' => $request->user()->id,
            'verified_by' => null, 'verified_at' => null,
        ])->save();
        if ($request->hasFile('file')) {
            $document = $this->followUps->attach($request->file('file'), $request->user(), $value, DocumentKind::ImpactEvidence);
            $value->forceFill(['evidence_document_id' => $document->id])->save();
        }

        $this->audit->log('indicator_value.resubmitted', $value, $before, ['value' => (float) $value->value, 'status' => 'entered'], $request->user());
        $this->followUps->notifyStaff($opportunity, 'indicator_submitted', ['indicator' => $indicator->name]);

        return $this->ok($this->indicatorPayload($indicator));
    }

    // ---------------------------------------------------------------- ENT-10 updates / PRJ-03 remediation

    /** POST /entrepreneur/follow-up/{uuid}/updates — published to the connected investors (or held for review). */
    public function storeUpdate(InvestorUpdateRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->owned($request, $opportunity);
        $data = $request->validated();
        $review = $this->followUps->updatesRequireReview();

        $update = InvestorUpdate::query()->create([
            'opportunity_id' => $opportunity->id,
            'author_id' => $request->user()->id,
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'metrics' => ! empty($data['include_indicators']) ? $this->indicatorSnapshot($opportunity) : null,
            'status' => $review ? InvestorUpdateStatus::PendingReview : InvestorUpdateStatus::Published,
            'sent_at' => $review ? null : now(),
        ]);
        if ($request->hasFile('file')) {
            $this->followUps->attach($request->file('file'), $request->user(), $update, DocumentKind::Other);
        }

        $this->audit->log('investor_update.created', $update, null, ['title' => $update->title, 'status' => $update->status->value], $request->user());
        if ($review) {
            $this->followUps->notifyStaff($opportunity, 'update_pending_review', ['title' => $update->title ?: __('notifications.follow_up.update_posted.untitled')]);
        } else {
            $this->followUps->notifyInvestors($opportunity, $update);
        }

        return response()->json(['data' => $this->presenter->update($update, FollowUpPresenter::OWNER)], 201);
    }

    /** PUT /entrepreneur/follow-up/{uuid}/remediation — PRJ-03 remediation plan. */
    public function remediation(RemediationPlanRequest $request, Opportunity $opportunity): JsonResponse
    {
        $followUp = $this->owned($request, $opportunity);
        $before = ['remediation_plan' => $followUp->remediation_plan];
        $followUp->forceFill(['remediation_plan' => $request->validated('remediation_plan'), 'remediation_updated_at' => now()])->save();
        $this->audit->log('follow_up.remediation_updated', $followUp, $before, ['remediation_plan' => $followUp->remediation_plan], $request->user());
        $this->followUps->notifyStaff($opportunity, 'remediation_updated');

        return $this->detail($opportunity, $followUp);
    }

    // ---------------------------------------------------------------- helpers

    private function owned(Request $request, Opportunity $opportunity): FollowUp
    {
        if (! $opportunity->isOwnedBy($request->user())) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }
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

    /** The plan's budget shares may not exceed 100 %. */
    private function assertBudget(Opportunity $opportunity, float $share, ?int $exceptId = null): void
    {
        if ($this->followUps->budgetShareTotal($opportunity, $exceptId) + $share > 100.0001) {
            throw new DomainException(__('messages.follow_up.budget_exceeded'), 'validation_failed', 422, ['budget_share' => [__('messages.follow_up.budget_exceeded')]]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{milestone_id: int, status: string, note: ?string}>
     */
    private function milestoneUpdates(Opportunity $opportunity, array $rows): array
    {
        $ids = $opportunity->milestones()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $out = [];
        foreach ($rows as $i => $row) {
            $id = (int) ($row['milestone_id'] ?? 0);
            if (! in_array($id, $ids, true)) {
                throw new DomainException(__('messages.errors.not_found'), 'validation_failed', 422, ["milestone_updates.{$i}.milestone_id" => [__('messages.follow_up.unknown_milestone')]]);
            }
            $out[] = ['milestone_id' => $id, 'status' => (string) $row['status'], 'note' => isset($row['note']) && trim((string) $row['note']) !== '' ? trim((string) $row['note']) : null];
        }

        return $out;
    }

    /** @param array<int, array{milestone_id: int, status: string, note: ?string}> $updates */
    private function applyMilestoneUpdates(Opportunity $opportunity, array $updates): void
    {
        foreach ($updates as $u) {
            $opportunity->milestones()->whereKey($u['milestone_id'])->where('status', '!=', MilestoneStatus::Done->value)->update(['status' => $u['status']]);
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function reportFields(array $data): array
    {
        return collect($data)->only(['narrative', 'achieved', 'delayed', 'risks', 'support_needed', 'spend_amount', 'execution_status'])
            ->map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v)
            ->all() + ['delayed' => null, 'risks' => null, 'support_needed' => null, 'spend_amount' => null, 'execution_status' => null];
    }

    /** ENT-10 — snapshot of the latest verified value per indicator (investors never see unverified values). @return array<int, array<string, mixed>> */
    private function indicatorSnapshot(Opportunity $opportunity): array
    {
        return $opportunity->indicators()->with(['values' => fn ($q) => $q->where('status', IndicatorValueStatus::Verified->value)->orderBy('period_end')])->get()
            ->map(function (ImpactIndicator $i): ?array {
                $latest = $i->values->last();

                return $latest ? [
                    'indicator_id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'value' => (float) $latest->value,
                    'period_end' => $latest->period_end->toDateString(), 'target' => $i->target !== null ? (float) $i->target : null,
                ] : null;
            })
            ->filter()->values()->all();
    }

    private function periodLabel(ProgressReport $report): string
    {
        return $report->period_start->toDateString().' — '.$report->period_end->toDateString();
    }

    /** @return array<string, mixed> */
    private function indicatorPayload(ImpactIndicator $indicator): array
    {
        return $this->presenter->indicator($indicator->load(['values' => fn ($q) => $q->orderBy('period_end')->orderBy('id'), 'values.evidence']), FollowUpPresenter::OWNER);
    }

    private function detail(Opportunity $opportunity, FollowUp $followUp): JsonResponse
    {
        return $this->ok($this->presenter->detail(FollowUpPresenter::load($opportunity->refresh()), $followUp->refresh(), FollowUpPresenter::OWNER));
    }
}
