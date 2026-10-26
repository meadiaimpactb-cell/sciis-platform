<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\InfoRequestStatus;
use App\Enums\InterestStatus;
use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MembershipResource;
use App\Models\CompletionRequest;
use App\Models\ContactMessage;
use App\Models\EvaluationAssignment;
use App\Models\InfoRequest;
use App\Models\Interest;
use App\Models\MeetingAction;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\Teaser;
use App\Models\User;
use App\Services\EvaluationService;
use App\Services\MatchingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADM-07 — staff dashboard.
 *
 * Contract (web `DashboardAdmin`): today{new,overdue,pending_memberships,interest_rate}, pipeline[{status,count}],
 * membership_requests[], queue[] (QueueItem), evaluation_focus, candidates_focus, my_tasks[] ({id,title,href,due_at}).
 * The historical keys (today.new_*, pipeline[].total, overdue{}, totals{}, my_task_counts{}) are kept for other clients.
 */
class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly EvaluationService $evaluations,
        private readonly MatchingService $matching,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $today = now()->startOfDay();
        $evaluatorOnly = $user->hasRole('evaluator') && ! $user->hasAnyRole(['super_admin', 'reviewer', 'matching_officer', 'membership_officer']);

        $pipeline = Opportunity::query()->whereNotNull('submitted_at')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $pipelineRows = collect(OpportunityStatus::cases())->reject(fn ($s) => $s === OpportunityStatus::Draft)
            ->map(fn (OpportunityStatus $s): array => ['status' => $s->value, 'label' => $s->label(), 'color' => $s->color(), 'count' => (int) ($pipeline[$s->value] ?? 0), 'total' => (int) ($pipeline[$s->value] ?? 0)])->values()->all();

        $overdueOpportunities = Opportunity::query()->overdue()->count();
        $pendingMemberships = Membership::query()->where('status', MembershipStatus::UnderReview->value)->count();

        return $this->ok([
            'today' => [
                'new' => Opportunity::query()->where('status', OpportunityStatus::New->value)->count(),
                'overdue' => $overdueOpportunities,
                'pending_memberships' => $pendingMemberships,
                'interest_rate' => $this->interestRate(),
                'new_opportunities' => Opportunity::query()->where('submitted_at', '>=', $today)->count(),
                'new_memberships' => Membership::query()->where('submitted_at', '>=', $today)->count(),
                'new_interests' => Interest::query()->where('created_at', '>=', $today)->count(),
                'new_contact_messages' => ContactMessage::query()->where('created_at', '>=', $today)->count(),
            ],
            'pipeline' => $pipelineRows,
            'membership_requests' => $this->membershipRequests($request, $user),
            'queue' => $this->queue($user, $evaluatorOnly),
            'evaluation_focus' => $evaluatorOnly ? null : $this->evaluationFocus($user),
            'candidates_focus' => $evaluatorOnly ? null : $this->candidatesFocus($user),
            'my_tasks' => $this->myTasks($user),
            'my_task_counts' => $this->myTaskCounts($user),
            'overdue' => [
                'opportunities' => $overdueOpportunities,
                'owner_decisions' => Interest::query()->where('status', InterestStatus::AwaitingOwnerApproval->value)->where('owner_decision_due_at', '<', now())->count(),
                'evaluations' => EvaluationAssignment::query()->whereIn('status', [AssignmentStatus::Assigned->value, AssignmentStatus::CoiDeclared->value])->where('due_at', '<', now())->count(),
            ],
            'totals' => [
                'approved_members' => Membership::query()->where('status', MembershipStatus::Approved->value)->count(),
                'connected' => Opportunity::query()->whereIn('status', array_map(fn ($s) => $s->value, OpportunityStatus::connectedOrLater()))->count(),
            ],
        ]);
    }

    /** Share (in %) of eligible-or-later opportunities that attracted at least one interest. */
    private function interestRate(): int
    {
        $statuses = [OpportunityStatus::Eligible->value, ...array_map(fn ($s) => $s->value, OpportunityStatus::connectedOrLater())];
        $eligible = Opportunity::query()->whereIn('status', $statuses)->count();
        if ($eligible === 0) {
            return 0;
        }
        $withInterest = Opportunity::query()->whereIn('status', $statuses)->has('interests')->count();

        return (int) round($withInterest / $eligible * 100);
    }

    /** @return array<int, array<string, mixed>> */
    private function membershipRequests(Request $request, User $user): array
    {
        if (! $user->can('memberships.review')) {
            return [];
        }

        $rows = Membership::query()->with(['user.organization', 'user.investorProfile'])
            ->whereIn('status', [MembershipStatus::UnderReview->value, MembershipStatus::NeedsCompletion->value])
            ->orderBy('submitted_at')->limit(10)->get();

        return MembershipResource::collection($rows)->resolve($request);
    }

    /** @return array<int, array<string, mixed>> */
    private function queue(User $user, bool $evaluatorOnly): array
    {
        $query = Opportunity::query()->with(['sector', 'owner', 'assignedReviewer'])
            ->whereNotNull('submitted_at')
            ->whereNotIn('status', [OpportunityStatus::Draft->value, OpportunityStatus::Withdrawn->value, OpportunityStatus::Closed->value]);

        if ($evaluatorOnly) {
            $query->whereHas('evaluationAssignments', fn (Builder $a) => $a->where('evaluator_user_id', $user->id));
        }

        return $query->orderByDesc('updated_at')->limit(50)->get()->map(fn (Opportunity $o): array => self::queueItem($o))->values()->all();
    }

    /** Compact row for the work queue (same keys as the staff-only fields of OpportunityFullResource). @return array<string, mixed> */
    public static function queueItem(Opportunity $o): array
    {
        $due = match ($o->status) {
            OpportunityStatus::New => $o->review_due_at,
            OpportunityStatus::NeedsCompletion => $o->completion_due_at,
            OpportunityStatus::UnderReview => $o->evaluation_due_at,
            default => null,
        };
        $daysLeft = $due ? (int) ceil(now()->diffInDays($due, false)) : null;

        return [
            'uuid' => $o->uuid,
            'reference_no' => $o->reference_no,
            'name' => $o->name,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'sector' => $o->sector ? ['id' => $o->sector->id, 'key' => $o->sector->key, 'label' => $o->sector->label(), 'label_ar' => $o->sector->label_ar, 'label_en' => $o->sector->label_en] : null,
            'owner_name' => $o->owner?->name,
            'assignee' => $o->assignedReviewer ? ['id' => $o->assignedReviewer->id, 'name' => $o->assignedReviewer->name] : null,
            'sla_due_at' => $due?->toIso8601String(),
            'sla_days_left' => $daysLeft,
            'is_overdue' => $o->overdueKind() !== null,
            'submitted_at' => $o->submitted_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
        ];
    }

    /** First under_review opportunity with both evaluators assigned + its comparison panel (EVL-03). @return array<string, mixed>|null */
    private function evaluationFocus(User $user): ?array
    {
        if (! $user->can('evaluations.approve') && ! $user->can('evaluations.assign')) {
            return null;
        }

        $opportunity = Opportunity::query()
            ->where('status', OpportunityStatus::UnderReview->value)
            ->whereHas('evaluationAssignments', fn (Builder $a) => $a->where('status', '!=', AssignmentStatus::Withdrawn->value), '>=', 2)
            ->orderBy('evaluation_due_at')->orderBy('id')
            ->first();

        if ($opportunity === null) {
            return null;
        }

        return [
            'opportunity' => ['uuid' => $opportunity->uuid, 'name' => $opportunity->name, 'reference_no' => $opportunity->reference_no],
            'panel' => $this->evaluations->comparison($opportunity),
        ];
    }

    /** First eligible opportunity (approved teaser) without nominations + ranked candidates (MAT-02). @return array<string, mixed>|null */
    private function candidatesFocus(User $user): ?array
    {
        if (! $user->can('nominations.manage')) {
            return null;
        }

        $opportunity = Opportunity::query()->where('status', OpportunityStatus::Eligible->value)->withApprovedTeaser()->doesntHave('nominations')->orderBy('id')->first();
        if ($opportunity === null) {
            return null;
        }

        return [
            'opportunity' => ['uuid' => $opportunity->uuid, 'name' => $opportunity->name, 'reference_no' => $opportunity->reference_no],
            'candidates' => $this->matching->candidateRows($opportunity, 5),
            'max_nominations' => (int) setting('opportunity.max_nominations_per_opportunity', 5),
        ];
    }

    /** @return array<int, array{id: string, title: string, href: string, due_at: string|null, kind: string}> */
    private function myTasks(User $user): array
    {
        $tasks = [];
        $task = function (string $kind, int|string $id, string $title, string $href, ?\DateTimeInterface $due) use (&$tasks): void {
            $tasks[] = ['id' => $kind.':'.$id, 'kind' => $kind, 'title' => $title, 'href' => $href, 'due_at' => $due?->format(DATE_ATOM)];
        };

        if ($user->hasAnyRole(['reviewer', 'super_admin'])) {
            Opportunity::query()->where('status', OpportunityStatus::New->value)
                ->where(fn (Builder $q) => $q->whereNull('assigned_reviewer_id')->orWhere('assigned_reviewer_id', $user->id))
                ->orderBy('review_due_at')->limit(10)->get()
                ->each(fn (Opportunity $o) => $task('review', $o->id, __('messages.dashboard.task_screen', ['name' => $o->name]), '/app/admin/opportunities/'.$o->uuid, $o->review_due_at));

            CompletionRequest::query()->with('opportunity')->open()->where('requested_by', $user->id)->orderBy('due_at')->limit(10)->get()
                ->each(fn (CompletionRequest $c) => $task('completion', $c->id, __('messages.dashboard.task_completion', ['name' => $c->opportunity?->name ?? '']), '/app/admin/opportunities/'.$c->opportunity?->uuid.'?tab=completion', $c->due_at));
        }

        if ($user->hasRole('evaluator')) {
            EvaluationAssignment::query()->with('opportunity')->where('evaluator_user_id', $user->id)
                ->whereIn('status', [AssignmentStatus::Assigned->value, AssignmentStatus::CoiDeclared->value])->orderBy('due_at')->limit(10)->get()
                ->each(fn (EvaluationAssignment $a) => $task('evaluation', $a->id, __('messages.dashboard.task_evaluate', ['name' => $a->opportunity?->name ?? '']), '/app/admin/opportunities/'.$a->opportunity?->uuid.'?tab=evaluation', $a->due_at));
        }

        if ($user->hasAnyRole(['membership_officer', 'super_admin'])) {
            Membership::query()->with('user')->where('status', MembershipStatus::UnderReview->value)->orderBy('submitted_at')->limit(10)->get()
                ->each(fn (Membership $m) => $task('membership', $m->id, __('messages.dashboard.task_membership', ['name' => $m->user?->name ?? '']), '/app/admin/memberships/'.$m->id, null));
        }

        if ($user->hasAnyRole(['matching_officer', 'super_admin'])) {
            InfoRequest::query()->where('status', InfoRequestStatus::Sent->value)->latest('id')->limit(10)->get()
                ->each(fn (InfoRequest $r) => $task('info_request', $r->id, __('messages.dashboard.task_info_request', ['subject' => $r->subject]), '/app/admin/info-requests', null));
            Interest::query()->with('opportunity')->where('status', InterestStatus::Shared->value)->latest('id')->limit(10)->get()
                ->each(fn (Interest $i) => $task('interest', $i->id, __('messages.dashboard.task_interest', ['name' => $i->opportunity?->name ?? '']), '/app/admin/interests', null));
        }

        // MTG-02 — unfinished meeting actions owned by the user.
        MeetingAction::query()->where('owner_user_id', $user->id)->whereNull('done_at')->orderBy('due_at')->limit(10)->get()
            ->each(fn (MeetingAction $a) => $task('meeting_action', $a->id, __('messages.dashboard.task_meeting_action', ['description' => $a->description]), '/app/admin/meetings', $a->due_at));

        usort($tasks, fn (array $a, array $b): int => ($a['due_at'] ?? '9999') <=> ($b['due_at'] ?? '9999'));

        return $tasks;
    }

    /** @return array<string, int> */
    private function myTaskCounts(User $user): array
    {
        $counts = [];
        if ($user->hasAnyRole(['reviewer', 'super_admin'])) {
            $counts['to_screen'] = Opportunity::query()->where('status', OpportunityStatus::New->value)->where(fn ($q) => $q->whereNull('assigned_reviewer_id')->orWhere('assigned_reviewer_id', $user->id))->count();
        }
        if ($user->hasRole('evaluator')) {
            $counts['to_evaluate'] = EvaluationAssignment::query()->where('evaluator_user_id', $user->id)->whereIn('status', [AssignmentStatus::Assigned->value, AssignmentStatus::CoiDeclared->value])->count();
        }
        if ($user->hasAnyRole(['membership_officer', 'super_admin'])) {
            $counts['memberships_to_review'] = Membership::query()->where('status', MembershipStatus::UnderReview->value)->count();
        }
        if ($user->hasAnyRole(['matching_officer', 'super_admin'])) {
            $counts['teasers_pending'] = Teaser::query()->whereNull('approved_by_council_at')->count();
            $counts['info_requests_open'] = InfoRequest::query()->where('status', InfoRequestStatus::Sent->value)->count();
            $counts['interests_shared'] = Interest::query()->where('status', InterestStatus::Shared->value)->count();
            $counts['eligible_without_nomination'] = Opportunity::query()->where('status', OpportunityStatus::Eligible->value)->withApprovedTeaser()->doesntHave('nominations')->count();
        }
        $counts['meeting_actions_open'] = MeetingAction::query()->where('owner_user_id', $user->id)->whereNull('done_at')->count();

        return $counts;
    }
}
