<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EvaluationResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignEvaluatorsRequest;
use App\Http\Requests\Admin\CoiRequest;
use App\Http\Requests\Admin\DecideEvaluationRequest;
use App\Http\Requests\Admin\EvaluationCriterionRequest;
use App\Http\Requests\Admin\ScoreRequest;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationCriterion;
use App\Models\Opportunity;
use App\Services\AuditLogger;
use App\Services\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** EVL-01..03 + FRM-03 (criteria management). */
class AdminEvaluationController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluations, private readonly AuditLogger $audit) {}

    /** POST /admin/opportunities/{uuid}/evaluation/assign — EVL-02 */
    public function assign(AssignEvaluatorsRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('evaluate', $opportunity);
        $assignments = $this->evaluations->assign($opportunity, $request->validated('evaluator_ids'), $request->user());

        return response()->json(['data' => $assignments->map(fn (EvaluationAssignment $a): array => $this->assignmentArray($a))->all()], 201);
    }

    /** POST /admin/evaluation-assignments/{id}/coi */
    public function coi(CoiRequest $request, EvaluationAssignment $assignment): JsonResponse
    {
        $this->authorize('declareCoi', $assignment);
        $assignment = $this->evaluations->declareCoi($assignment, $request->user(), (bool) $request->validated('has_conflict'));

        return $this->ok($this->assignmentArray($assignment));
    }

    /** GET /admin/evaluation-assignments/{id}/score — scoring form (criteria + existing scores). */
    public function scoreForm(Request $request, EvaluationAssignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);
        $criteria = $this->evaluations->activeCriteria();

        return $this->ok([
            'assignment' => $this->assignmentArray($assignment),
            'can_score' => $assignment->canScore(),
            'criteria' => $criteria->groupBy(fn (EvaluationCriterion $c): string => $c->axis->value)->map(fn ($group) => $group->map(fn (EvaluationCriterion $c): array => [
                'key' => $c->key, 'label' => $c->label(), 'weight' => (float) $c->weight, 'scale_descriptions' => $c->scale_descriptions, 'version' => $c->version,
            ])->values())->all(),
            'evaluation' => $assignment->evaluation?->only(['scores', 'axis_totals', 'overall', 'recommendation', 'comment', 'criteria_version', 'submitted_at']),
            'results' => EvaluationResult::options(),
        ]);
    }

    /** POST /admin/evaluation-assignments/{id}/score — EVL-01 */
    public function score(ScoreRequest $request, EvaluationAssignment $assignment): JsonResponse
    {
        $this->authorize('score', $assignment);

        $evaluation = $this->evaluations->score(
            $assignment,
            $request->user(),
            $request->validated('scores'),
            EvaluationResult::from($request->validated('recommendation')),
            $request->validated('comment'),
        );

        return response()->json(['data' => $evaluation->only(['id', 'scores', 'axis_totals', 'overall', 'recommendation', 'comment', 'criteria_version', 'submitted_at'])], 201);
    }

    /** GET /admin/opportunities/{uuid}/evaluation — EVL-03 side by side + diffs. */
    public function comparison(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('view', $opportunity);
        $user = $request->user();
        if (! $user->can('evaluations.approve') && ! $user->can('evaluations.assign')) {
            abort(403, __('messages.errors.forbidden'));
        }

        return $this->ok($this->evaluations->comparison($opportunity));
    }

    /** POST /admin/opportunities/{uuid}/evaluation/decide — EVL-03 */
    public function decide(DecideEvaluationRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('decideEvaluation', $opportunity);

        $decision = $this->evaluations->decide(
            $opportunity,
            $request->user(),
            EvaluationResult::from($request->validated('result')),
            $request->validated('conditions'),
            $request->validated('reasons'),
        );

        return response()->json(['data' => $decision->only(['id', 'result', 'conditions', 'reasons', 'decided_at'])], 201);
    }

    /** GET /admin/evaluation-criteria — FRM-03 */
    public function criteria(Request $request): JsonResponse
    {
        $list = EvaluationCriterion::query()->orderBy('axis')->orderBy('sort')->get();

        return response()->json(['data' => $list->map(fn (EvaluationCriterion $c): array => $c->toArray() + ['label' => $c->label()])->all()]);
    }

    /** POST /admin/evaluation-criteria */
    public function storeCriterion(EvaluationCriterionRequest $request): JsonResponse
    {
        if (! $request->user()->can('settings.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
        $criterion = EvaluationCriterion::query()->create($request->validated() + ['version' => EvaluationCriterion::currentVersion()]);
        $this->audit->log('evaluation_criterion.created', $criterion, null, $criterion->toArray(), $request->user());

        return response()->json(['data' => $criterion->toArray()], 201);
    }

    /** PATCH /admin/evaluation-criteria/{id} */
    public function updateCriterion(EvaluationCriterionRequest $request, EvaluationCriterion $criterion): JsonResponse
    {
        if (! $request->user()->can('settings.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
        $before = $criterion->toArray();
        $criterion->fill($request->validated())->save();
        $this->audit->log('evaluation_criterion.updated', $criterion, $before, $criterion->getChanges(), $request->user());

        return $this->ok($criterion->toArray());
    }

    /** @return array<string, mixed> */
    private function assignmentArray(EvaluationAssignment $a): array
    {
        return [
            'id' => $a->id,
            'opportunity_uuid' => $a->opportunity?->uuid,
            'evaluator' => ['id' => $a->evaluator_user_id, 'name' => $a->evaluator?->name],
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'coi_declared_at' => $a->coi_declared_at?->toIso8601String(),
            'coi_has_conflict' => $a->coi_has_conflict,
            'due_at' => $a->due_at?->toIso8601String(),
        ];
    }
}
