<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\InfoRequestStatus;
use App\Enums\InterestStatus;
use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\EvaluationAssignment;
use App\Models\InfoRequest;
use App\Models\Interest;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\Teaser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADM-07 — staff dashboard: today counters, pipeline by status, overdue, my_tasks. */
class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now()->startOfDay();

        $pipeline = Opportunity::query()->whereNotNull('submitted_at')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $pipelineRows = collect(OpportunityStatus::cases())->reject(fn ($s) => $s === OpportunityStatus::Draft)
            ->map(fn (OpportunityStatus $s): array => ['status' => $s->value, 'label' => $s->label(), 'color' => $s->color(), 'total' => (int) ($pipeline[$s->value] ?? 0)])->values()->all();

        $myTasks = [];
        if ($user->hasAnyRole(['reviewer', 'super_admin'])) {
            $myTasks['to_screen'] = Opportunity::query()->where('status', OpportunityStatus::New->value)->where(fn ($q) => $q->whereNull('assigned_reviewer_id')->orWhere('assigned_reviewer_id', $user->id))->count();
        }
        if ($user->hasRole('evaluator')) {
            $myTasks['to_evaluate'] = EvaluationAssignment::query()->where('evaluator_user_id', $user->id)->whereIn('status', [AssignmentStatus::Assigned->value, AssignmentStatus::CoiDeclared->value])->count();
        }
        if ($user->hasAnyRole(['membership_officer', 'super_admin'])) {
            $myTasks['memberships_to_review'] = Membership::query()->where('status', MembershipStatus::UnderReview->value)->count();
        }
        if ($user->hasAnyRole(['matching_officer', 'super_admin'])) {
            $myTasks['teasers_pending'] = Teaser::query()->whereNull('approved_by_council_at')->count();
            $myTasks['info_requests_open'] = InfoRequest::query()->where('status', InfoRequestStatus::Sent->value)->count();
            $myTasks['interests_shared'] = Interest::query()->where('status', InterestStatus::Shared->value)->count();
            $myTasks['eligible_without_nomination'] = Opportunity::query()->where('status', OpportunityStatus::Eligible->value)->withApprovedTeaser()->doesntHave('nominations')->count();
        }

        return $this->ok([
            'today' => [
                'new_opportunities' => Opportunity::query()->where('submitted_at', '>=', $today)->count(),
                'new_memberships' => Membership::query()->where('submitted_at', '>=', $today)->count(),
                'new_interests' => Interest::query()->where('created_at', '>=', $today)->count(),
                'new_contact_messages' => ContactMessage::query()->where('created_at', '>=', $today)->count(),
            ],
            'pipeline' => $pipelineRows,
            'overdue' => [
                'opportunities' => Opportunity::query()->overdue()->count(),
                'owner_decisions' => Interest::query()->where('status', InterestStatus::AwaitingOwnerApproval->value)->where('owner_decision_due_at', '<', now())->count(),
                'evaluations' => EvaluationAssignment::query()->whereIn('status', [AssignmentStatus::Assigned->value, AssignmentStatus::CoiDeclared->value])->where('due_at', '<', now())->count(),
            ],
            'my_tasks' => $myTasks,
            'totals' => [
                'approved_members' => Membership::query()->where('status', MembershipStatus::Approved->value)->count(),
                'connected' => Opportunity::query()->whereIn('status', array_map(fn ($s) => $s->value, OpportunityStatus::connectedOrLater()))->count(),
            ],
        ]);
    }
}
