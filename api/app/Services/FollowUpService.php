<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentKind;
use App\Enums\ExecutionStatus;
use App\Enums\IndicatorFrequency;
use App\Enums\InterestStatus;
use App\Enums\InvestorUpdateStatus;
use App\Enums\MilestonePlanStatus;
use App\Enums\OpportunityStatus;
use App\Enums\ProgressReportStatus;
use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\FollowUp;
use App\Models\IndicatorValue;
use App\Models\InvestorUpdate;
use App\Models\Milestone;
use App\Models\Opportunity;
use App\Models\OpportunityStatusTransition;
use App\Models\ProgressReport;
use App\Models\User;
use App\Notifications\FollowUpNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * PRJ-01..03 / IMP-01..02 / ENT-10 / INV-06 — post-connection follow-up rules shared by the owner, staff and
 * investor endpoints and the daily reminder command:
 *  - follow-up covers negotiating / in_execution / operating; periodic reports are due once execution started;
 *  - report periods run back to back from the start of execution (frequency snapshotted per project), each due
 *    `follow_up.report_grace_days` after its end;
 *  - execution status (PRJ-03): on_track; delayed when a milestone of the approved plan or a report is overdue;
 *    stalled when the worst delay exceeds `follow_up.stalled_after_days`. A staff override (with reason) wins.
 *    Changes are audited and notified; reaching "stalled" escalates to staff with `impact.verify` once.
 */
class FollowUpService
{
    /** @var array<string, int> */
    public const FREQUENCY_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12];

    private const MAX_PERIODS = 120;

    /** @var Collection<int, User>|null */
    private ?Collection $staff = null;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly DocumentService $documents,
    ) {}

    // ---------------------------------------------------------------- scope

    /** @return array<int, OpportunityStatus> */
    public static function followUpStatuses(): array
    {
        return [OpportunityStatus::Negotiating, OpportunityStatus::InExecution, OpportunityStatus::Operating];
    }

    /** @return array<int, OpportunityStatus> */
    public static function reportingStatuses(): array
    {
        return [OpportunityStatus::InExecution, OpportunityStatus::Operating];
    }

    /** @param array<int, OpportunityStatus> $statuses @return array<int, string> */
    public static function values(array $statuses): array
    {
        return array_map(fn (OpportunityStatus $s): string => $s->value, $statuses);
    }

    public function inFollowUp(Opportunity $opportunity): bool
    {
        return $opportunity->status->in(self::followUpStatuses());
    }

    public function isReporting(Opportunity $opportunity): bool
    {
        return $opportunity->status->in(self::reportingStatuses());
    }

    /** The follow-up record (created on first use); the reporting anchor is set once execution has started. */
    public function ensure(Opportunity $opportunity): FollowUp
    {
        $followUp = FollowUp::query()->firstOrCreate(['opportunity_id' => $opportunity->id]);

        if ($followUp->execution_started_at === null && $this->isReporting($opportunity)) {
            $started = OpportunityStatusTransition::query()
                ->where('opportunity_id', $opportunity->id)
                ->whereIn('to_status', self::values(self::reportingStatuses()))
                ->orderBy('id')
                ->value('created_at');
            $followUp->execution_started_at = $started ? Carbon::parse($started) : now();
            $followUp->report_frequency ??= $this->defaultFrequency();
            $followUp->save();
        }

        return $followUp;
    }

    // ---------------------------------------------------------------- settings

    public function defaultFrequency(): IndicatorFrequency
    {
        $value = IndicatorFrequency::tryFrom((string) $this->settings->get('follow_up.report_frequency', 'quarterly'));

        return $value ?? IndicatorFrequency::Quarterly;
    }

    public function frequency(FollowUp $followUp): IndicatorFrequency
    {
        return $followUp->report_frequency ?? $this->defaultFrequency();
    }

    public function graceDays(): int
    {
        return max(0, $this->settings->int('follow_up.report_grace_days', 15));
    }

    /** Days before a report due date on which the owner is reminded (0 = on the day), descending. @return array<int, int> */
    public function reminderDays(): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', $this->settings->array('follow_up.report_reminder_days', [7, 0])), fn (int $d): bool => $d >= 0)));
        rsort($days);

        return $days;
    }

    /** Days after a missed due date on which the owner is reminded again, ascending. @return array<int, int> */
    public function overdueReminderDays(): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', $this->settings->array('follow_up.report_overdue_reminder_days', [3, 10])), fn (int $d): bool => $d >= 1)));
        sort($days);

        return $days;
    }

    public function stalledAfterDays(): int
    {
        return max(1, $this->settings->int('follow_up.stalled_after_days', 45));
    }

    public function updatesRequireReview(): bool
    {
        return $this->settings->bool('follow_up.updates_require_review', false);
    }

    // ---------------------------------------------------------------- PRJ-02 report schedule

    /**
     * Report periods that have started, oldest first, each with its report (matched on the period start) and a
     * state: submitted | accepted | changes_requested (has a report) · current (running) · due (ended, within the
     * grace period) · overdue.
     *
     * @param  Collection<int, ProgressReport>|null  $reports
     * @return array<int, array{start: Carbon, end: Carbon, due_on: Carbon, report: ?ProgressReport, state: string, days_overdue: int}>
     */
    public function periods(Opportunity $opportunity, FollowUp $followUp, ?Collection $reports = null): array
    {
        if (! $this->isReporting($opportunity) || $followUp->execution_started_at === null) {
            return [];
        }

        $months = self::FREQUENCY_MONTHS[$this->frequency($followUp)->value] ?? 3;
        $grace = $this->graceDays();
        $today = today();
        $anchor = $followUp->execution_started_at->copy()->startOfDay();
        $byStart = ($reports ?? $opportunity->progressReports()->get())->keyBy(fn (ProgressReport $r): string => $r->period_start->toDateString());

        $out = [];
        for ($k = 0; $k < self::MAX_PERIODS; $k++) {
            $start = $anchor->copy()->addMonthsNoOverflow($k * $months);
            if ($start->gt($today)) {
                break;
            }
            $end = $anchor->copy()->addMonthsNoOverflow(($k + 1) * $months)->subDay();
            $due = $end->copy()->addDays($grace);
            $report = $byStart->get($start->toDateString());
            $state = match (true) {
                $report !== null => $report->status->value,
                $due->lt($today) => 'overdue',
                $end->lt($today) => 'due',
                default => 'current',
            };
            $out[] = [
                'start' => $start, 'end' => $end, 'due_on' => $due, 'report' => $report, 'state' => $state,
                'days_overdue' => $state === 'overdue' ? (int) $due->diffInDays($today, false) : 0,
            ];
        }

        return $out;
    }

    /** Periods the owner may still report on (started, no report yet). @return array<int, array<string, mixed>> */
    public function openPeriods(Opportunity $opportunity, FollowUp $followUp): array
    {
        return array_values(array_filter($this->periods($opportunity, $followUp), fn (array $p): bool => $p['report'] === null));
    }

    // ---------------------------------------------------------------- PRJ-03 execution status

    /** Worst delay in days (0 = nothing overdue) over the approved plan's milestones and the report schedule. */
    public function worstDelayDays(Opportunity $opportunity, FollowUp $followUp): int
    {
        $today = today();
        $days = [0];
        if ($followUp->plan_version > 0) {
            foreach ($opportunity->milestones as $milestone) {
                if ($milestone->isOverdue()) {
                    $days[] = max(1, (int) $milestone->due_at->copy()->startOfDay()->diffInDays($today, false));
                }
            }
        }
        foreach ($this->periods($opportunity, $followUp) as $period) {
            if ($period['state'] === 'overdue') {
                $days[] = max(1, $period['days_overdue']);
            }
        }

        return max($days);
    }

    public function compute(Opportunity $opportunity, FollowUp $followUp): ?ExecutionStatus
    {
        if (! $this->inFollowUp($opportunity)) {
            return null;
        }

        $delay = $this->worstDelayDays($opportunity, $followUp);

        return match (true) {
            $delay === 0 => ExecutionStatus::OnTrack,
            $delay > $this->stalledAfterDays() => ExecutionStatus::Stalled,
            default => ExecutionStatus::Delayed,
        };
    }

    /**
     * Recomputes the execution status, stores the effective one on the opportunity (`execution_status`, used by
     * reports), audits + notifies a change, and escalates a newly stalled project to the follow-up staff.
     */
    public function refreshStatus(Opportunity $opportunity, ?FollowUp $followUp = null, ?User $actor = null): FollowUp
    {
        $followUp ??= $this->ensure($opportunity);
        $opportunity->load('milestones');

        $computed = $this->compute($opportunity, $followUp);
        $followUp->computed_status = $computed;
        $followUp->status_computed_at = now();
        $effective = $computed === null ? null : ($followUp->status_override ?? $computed);
        $previous = $opportunity->execution_status;

        if ($previous !== $effective) {
            $opportunity->execution_status = $effective;
            $opportunity->save();
            $this->audit->log('follow_up.status_changed', $opportunity, ['execution_status' => $previous?->value], [
                'execution_status' => $effective?->value, 'computed' => $computed?->value, 'override' => $followUp->status_override?->value,
            ], $actor);

            if ($effective !== null && ! ($previous === null && $effective === ExecutionStatus::OnTrack)) {
                $this->notifyOwner($opportunity, 'status_changed', ['status' => $effective->label()]);
            }
        }

        if ($effective === ExecutionStatus::Stalled && $followUp->escalated_at === null) {
            $followUp->escalated_at = now();
            $this->audit->log('follow_up.escalated', $opportunity, null, ['execution_status' => $effective->value, 'delay_days' => $this->worstDelayDays($opportunity, $followUp)], $actor);
            $this->notifyStaff($opportunity, 'stalled_escalation');
        } elseif ($effective !== ExecutionStatus::Stalled && $followUp->escalated_at !== null) {
            $followUp->escalated_at = null;
        }

        $followUp->save();

        return $followUp;
    }

    /** Lists refresh a project at most once a day (the nightly command does the same for everyone). */
    public function refreshIfStale(Opportunity $opportunity): FollowUp
    {
        $followUp = $this->ensure($opportunity);
        if ($followUp->status_computed_at === null || $followUp->status_computed_at->lt(today())) {
            return $this->refreshStatus($opportunity, $followUp);
        }

        return $followUp;
    }

    // ---------------------------------------------------------------- PRJ-01 helpers

    public function assertPlanEditable(FollowUp $followUp): void
    {
        if (! $followUp->plan_status->isEditableByOwner()) {
            throw new DomainException(__('messages.follow_up.plan_locked'), 'plan_locked', 422);
        }
    }

    /** Sum of budget shares (%) of the plan, optionally excluding one milestone. */
    public function budgetShareTotal(Opportunity $opportunity, ?int $exceptId = null): float
    {
        return (float) $opportunity->milestones()->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('budget_share');
    }

    // ---------------------------------------------------------------- audiences

    /** INV-06 — investors whose interest on the project is connected or at the meeting stage. @return EloquentCollection<int, User> */
    public function connectedInvestors(Opportunity $opportunity): EloquentCollection
    {
        return User::query()
            ->whereHas('interests', fn ($q) => $q->where('opportunity_id', $opportunity->id)
                ->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value]))
            ->get();
    }

    /** INV-06 — the investor may follow this project. */
    public function investorMayView(Opportunity $opportunity, User $investor): bool
    {
        return $investor->isInvestor()
            && $opportunity->status->isConnectedOrLater()
            && $opportunity->isConnectedTo($investor);
    }

    /** @return Collection<int, User> */
    public function staffRecipients(): Collection
    {
        return $this->staff ??= User::query()->staff()->active()->permission('impact.verify')->get();
    }

    /** @param array<string, string|int|float|null> $params */
    public function notifyOwner(Opportunity $opportunity, string $key, array $params = []): void
    {
        $this->notifications->send($opportunity->owner, new FollowUpNotification($key, $opportunity, '/app/entrepreneur/follow-up/'.$opportunity->uuid, $params));
    }

    /** @param array<string, string|int|float|null> $params */
    public function notifyStaff(Opportunity $opportunity, string $key, array $params = []): void
    {
        $this->notifications->sendToMany($this->staffRecipients(), new FollowUpNotification($key, $opportunity, '/app/admin/follow-up/'.$opportunity->uuid, $params));
    }

    /** ENT-10 — in-app + e-mail notice to every connected investor. */
    public function notifyInvestors(Opportunity $opportunity, InvestorUpdate $update): void
    {
        $this->notifications->sendToMany($this->connectedInvestors($opportunity), new FollowUpNotification('update_posted', $opportunity, '/app/investor/portfolio/'.$opportunity->uuid, [
            'title' => $update->title ?: __('notifications.follow_up.update_posted.untitled'),
        ]));
    }

    // ---------------------------------------------------------------- documents (DOC-01..03 pipeline)

    public function attach(UploadedFile $file, User $owner, Model $parent, DocumentKind $kind): Document
    {
        return $this->documents->upload($file, $owner, $parent, $kind);
    }

    /** The opportunity a follow-up document belongs to, or null when the document is not a follow-up file. */
    public function documentOpportunity(Document $document): ?Opportunity
    {
        $parent = $document->documentable;

        return match (true) {
            $parent instanceof Milestone, $parent instanceof ProgressReport, $parent instanceof InvestorUpdate => $parent->opportunity,
            $parent instanceof IndicatorValue => $parent->indicator?->opportunity,
            default => null,
        };
    }

    /**
     * DOC-02 — staff with impact.verify and the owner open every follow-up file; a connected investor only the
     * attachments of accepted reports and published updates (milestone and indicator evidence stay internal).
     */
    public function documentVisibleTo(Document $document, User $user): bool
    {
        $opportunity = $this->documentOpportunity($document);
        if ($opportunity === null) {
            return false;
        }
        if ($user->isStaff()) {
            return $user->can('impact.verify');
        }
        if ($opportunity->isOwnedBy($user)) {
            return true;
        }
        if (! $this->investorMayView($opportunity, $user) || ! $user->isApprovedMember()) {
            return false;
        }
        $parent = $document->documentable;

        return ($parent instanceof ProgressReport && $parent->status === ProgressReportStatus::Accepted)
            || ($parent instanceof InvestorUpdate && $parent->status === InvestorUpdateStatus::Published);
    }

    // ---------------------------------------------------------------- staff workload

    /** Items waiting for a staff decision. @return array{plan: int, reports: int, indicator_values: int, updates: int, total: int} */
    public function pendingCounts(Opportunity $opportunity, FollowUp $followUp): array
    {
        $plan = $followUp->plan_status === MilestonePlanStatus::Submitted ? 1 : 0;
        $reports = $opportunity->progressReports()->where('status', ProgressReportStatus::Submitted->value)->count();
        $values = IndicatorValue::query()->whereHas('indicator', fn ($q) => $q->where('opportunity_id', $opportunity->id))->where('status', 'entered')->count();
        $updates = InvestorUpdate::query()->where('opportunity_id', $opportunity->id)->where('status', InvestorUpdateStatus::PendingReview->value)->count();

        return ['plan' => $plan, 'reports' => $reports, 'indicator_values' => $values, 'updates' => $updates, 'total' => $plan + $reports + $values + $updates];
    }
}
