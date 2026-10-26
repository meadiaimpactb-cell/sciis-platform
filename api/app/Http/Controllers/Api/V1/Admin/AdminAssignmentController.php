<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\WorkloadAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADM-08/09 — reviewer pickers with current workload, and EVL-02 evaluator suggestions (least workload, no COI). */
class AdminAssignmentController extends Controller
{
    public function __construct(private readonly WorkloadAssignment $workload) {}

    /** GET /admin/assignment/reviewers?for=opportunities|memberships */
    public function reviewers(Request $request): JsonResponse
    {
        if ($request->input('for') === 'memberships') {
            $this->authorize('viewAny', Membership::class);
            $users = User::query()->staff()->active()->permission('memberships.review')->orderBy('name')->get();
            $open = Membership::query()->whereIn('assigned_reviewer_id', $users->modelKeys())->whereIn('status', ['under_review', 'needs_completion'])
                ->selectRaw('assigned_reviewer_id, count(*) as total')->groupBy('assigned_reviewer_id')->pluck('total', 'assigned_reviewer_id');

            return $this->ok(['items' => $users->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'open' => (int) ($open[$u->id] ?? 0)])->all()]);
        }

        $this->authorize('viewAny', Opportunity::class);
        $workloads = $this->workload->reviewerWorkloads();

        return $this->ok([
            'items' => $this->workload->reviewerPool()->sortBy('name')->values()->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'open' => $workloads[$u->id] ?? 0])->all(),
            'auto_assign' => $this->workload->autoAssignEnabled(),
            'strategy' => $this->workload->strategy(),
        ]);
    }

    /** GET /admin/opportunities/{uuid}/evaluation/suggest — two evaluators by least open workload, excluding conflicts. */
    public function suggestEvaluators(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('evaluate', $opportunity);

        return $this->ok(['items' => $this->workload->suggestEvaluators($opportunity)->all()]);
    }
}
