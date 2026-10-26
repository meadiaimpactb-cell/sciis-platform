<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\EvaluationAxis;
use App\Enums\EvaluationResult;
use App\Enums\OpportunityStatus;
use App\Exceptions\DomainException;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationDecision;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\EvaluationDecidedNotification;
use App\Notifications\EvaluationDivergenceNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * EVL-01..03 — exactly two evaluators per opportunity, COI declaration before scoring,
 * criteria version snapshot per evaluation, side-by-side comparison with diffs, final decision.
 */
class EvaluationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * EVL-02 — assign exactly two evaluators.
     *
     * @param  array<int, int>  $evaluatorIds
     * @return Collection<int, EvaluationAssignment>
     */
    public function assign(Opportunity $opportunity, array $evaluatorIds, User $actor): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $evaluatorIds)));
        if (count($ids) !== 2) {
            throw new DomainException(__('messages.evaluation.exactly_two'), 'exactly_two_evaluators', 422);
        }
        if (! in_array($opportunity->status, [OpportunityStatus::UnderReview, OpportunityStatus::New], true)) {
            throw new DomainException(__('messages.evaluation.wrong_status'), 'evaluation_wrong_status', 422);
        }

        $evaluators = User::query()->staff()->active()->whereIn('id', $ids)->role('evaluator')->get();
        if ($evaluators->count() !== 2) {
            throw new DomainException(__('messages.evaluation.not_evaluators'), 'not_evaluators', 422);
        }

        return DB::transaction(function () use ($opportunity, $evaluators, $actor): Collection {
            // Withdraw any previous open assignments not in the new pair.
            $opportunity->evaluationAssignments()
                ->whereNotIn('evaluator_user_id', $evaluators->pluck('id'))
                ->where('status', '!=', AssignmentStatus::Scored->value)
                ->update(['status' => AssignmentStatus::Withdrawn->value]);

            $due = now()->addDays($this->settings->slaDays('evaluation'));
            $created = collect();

            foreach ($evaluators as $evaluator) {
                $assignment = EvaluationAssignment::query()->updateOrCreate(
                    ['opportunity_id' => $opportunity->id, 'evaluator_user_id' => $evaluator->id],
                    ['assigned_by' => $actor->id, 'status' => AssignmentStatus::Assigned, 'due_at' => $due],
                );
                $created->push($assignment);
                $this->audit->log('evaluation.assigned', $assignment, null, ['evaluator_user_id' => $evaluator->id], $actor);
            }

            if ($opportunity->evaluation_due_at === null) {
                $opportunity->evaluation_due_at = $due;
                $opportunity->save();
            }

            return $created;
        });
    }

    /** EVL-02 — COI gate: the evaluator declares before seeing the scoring form. */
    public function declareCoi(EvaluationAssignment $assignment, User $evaluator, bool $hasConflict): EvaluationAssignment
    {
        $this->assertEvaluator($assignment, $evaluator);

        $assignment->coi_declared_at = now();
        $assignment->coi_has_conflict = $hasConflict;
        $assignment->status = $hasConflict ? AssignmentStatus::Withdrawn : AssignmentStatus::CoiDeclared;
        $assignment->save();

        $this->audit->log('evaluation.coi_declared', $assignment, null, ['has_conflict' => $hasConflict], $evaluator);

        return $assignment;
    }

    /** The active criteria set the evaluator scores against (current version). */
    public function activeCriteria(): Collection
    {
        $version = EvaluationCriterion::currentVersion();

        return EvaluationCriterion::query()->active()->version($version)->orderBy('axis')->orderBy('sort')->get();
    }

    /**
     * EVL-01 — store scores with a snapshot of the criteria version.
     *
     * @param  array<string, array{score: int, note?: string|null}>  $scores
     */
    public function score(EvaluationAssignment $assignment, User $evaluator, array $scores, EvaluationResult $recommendation, ?string $comment): Evaluation
    {
        $this->assertEvaluator($assignment, $evaluator);

        if (! $assignment->canScore()) {
            throw new DomainException(__('messages.evaluation.coi_required'), 'coi_required', 422);
        }

        $criteria = $this->activeCriteria();
        $missing = $criteria->pluck('key')->diff(array_keys($scores));
        if ($missing->isNotEmpty()) {
            throw new DomainException(__('messages.evaluation.missing_scores'), 'missing_scores', 422, [
                'scores' => $missing->map(fn (string $k): string => __('messages.evaluation.missing_score_for', ['key' => $k]))->values()->all(),
            ]);
        }

        foreach ($scores as $key => $entry) {
            $score = (int) ($entry['score'] ?? 0);
            if ($score < 1 || $score > 5) {
                throw new DomainException(__('messages.evaluation.score_range'), 'score_range', 422, ['scores.'.$key => [__('messages.evaluation.score_range')]]);
            }
        }

        $axisTotals = $this->axisTotals($criteria, $scores);
        $overall = round(array_sum(array_column($axisTotals, 'weighted')) / max(1, count($axisTotals)), 2);

        $evaluation = DB::transaction(function () use ($assignment, $evaluator, $criteria, $scores, $recommendation, $comment, $axisTotals, $overall): Evaluation {
            $evaluation = Evaluation::query()->updateOrCreate(
                ['assignment_id' => $assignment->id],
                [
                    'criteria_version' => (int) $criteria->max('version'),
                    'criteria_snapshot' => $criteria->map(fn (EvaluationCriterion $c): array => $c->only(['axis', 'key', 'label_ar', 'label_en', 'weight', 'scale_descriptions', 'version']))->values()->all(),
                    'scores' => $scores,
                    'axis_totals' => $axisTotals,
                    'overall' => $overall,
                    'recommendation' => $recommendation,
                    'comment' => $comment,
                    'submitted_at' => now(),
                ],
            );

            $assignment->status = AssignmentStatus::Scored;
            $assignment->save();

            $this->audit->log('evaluation.scored', $evaluation, null, ['overall' => $overall, 'recommendation' => $recommendation->value], $evaluator);

            return $evaluation;
        });

        $this->alertDivergence($assignment->opportunity()->firstOrFail());

        return $evaluation;
    }

    /**
     * EVL-05 — once both evaluations are in, any criterion whose scores differ by the configured threshold
     * (`evaluation.score_diff_threshold`) alerts the supervisors (`evaluations.approve`) in-app and by e-mail.
     */
    private function alertDivergence(Opportunity $opportunity): void
    {
        $panel = $this->comparison($opportunity);
        if (! $panel['complete'] || $panel['diffs'] === []) {
            return;
        }

        $labels = collect($panel['criteria'])->pluck('label', 'key');
        $criteria = array_map(fn (array $d): array => [
            'key' => $d['criterion_key'],
            'label' => (string) ($labels[$d['criterion_key']] ?? $d['criterion_key']),
            'delta' => (int) $d['delta'],
        ], $panel['diffs']);

        $recipients = User::query()->staff()->active()->permission('evaluations.approve')->get();
        $this->notifications->sendToMany($recipients, new EvaluationDivergenceNotification($opportunity, $criteria, $panel['diff_threshold']));
        $this->audit->log('evaluation.divergence_flagged', $opportunity, null, ['threshold' => $panel['diff_threshold'], 'criteria' => array_column($criteria, 'delta', 'key'), 'recipients' => $recipients->count()]);
    }

    /**
     * EVL-03 — both evaluations side by side with per-criterion diffs.
     *
     * @return array<string, mixed>
     */
    public function comparison(Opportunity $opportunity, ?User $onlyEvaluator = null): array
    {
        $assignments = $opportunity->evaluationAssignments()
            ->with(['evaluator', 'evaluation'])
            ->where('status', '!=', AssignmentStatus::Withdrawn->value)
            // An evaluator without approve/assign rights only sees their own assignment (independent scoring, EVL-01).
            ->when($onlyEvaluator !== null, fn ($q) => $q->where('evaluator_user_id', $onlyEvaluator->id))
            ->orderBy('id')
            ->get();

        $evaluations = $assignments->map(fn (EvaluationAssignment $a): ?Evaluation => $a->evaluation)->filter()->values();
        $keys = $evaluations->flatMap(fn (Evaluation $e): array => array_keys($e->scores ?? []))->unique()->values();
        $threshold = $this->settings->scoreDiffThreshold();

        $criteria = [];
        foreach ($keys as $key) {
            $row = ['key' => $key, 'scores' => [], 'notes' => []];
            foreach ($evaluations as $i => $evaluation) {
                $row['scores'][$i] = (int) ($evaluation->scores[$key]['score'] ?? 0);
                $row['notes'][$i] = $evaluation->scores[$key]['note'] ?? null;
            }
            $values = array_values($row['scores']);
            $row['diff'] = count($values) >= 2 ? abs($values[0] - $values[1]) : 0;
            $row['flag'] = $row['diff'] >= $threshold; // EVL-05 — divergence worth discussing
            $criteria[] = $row;
        }

        $active = $this->activeCriteria();
        $labels = $active->keyBy('key');
        foreach ($criteria as &$row) {
            $c = $labels->get($row['key']);
            $row['label'] = $c?->label() ?? $row['key'];
            $row['axis'] = $c?->axis?->value;
        }
        unset($row);

        $decision = $opportunity->latestDecision()->with('decider')->first();

        return [
            'assignments' => $assignments->map(fn (EvaluationAssignment $a): array => [
                'id' => $a->id,
                'evaluator' => ['id' => $a->evaluator?->id, 'name' => $a->evaluator?->name],
                'status' => $a->status->value,
                'status_label' => $a->status->label(),
                'coi_declared_at' => $a->coi_declared_at?->toIso8601String(),
                'coi_has_conflict' => $a->coi_has_conflict,
                'due_at' => $a->due_at?->toIso8601String(),
                'evaluation' => $a->evaluation ? [
                    'id' => $a->evaluation->id,
                    'assignment_id' => $a->id,
                    'overall' => (float) $a->evaluation->overall,
                    // axis_totals[axis] = {sum, count, weighted (0..5), max}
                    'axis_totals' => $a->evaluation->axis_totals,
                    'scores' => $a->evaluation->scores ?? [],
                    'recommendation' => $a->evaluation->recommendation->value,
                    'recommendation_label' => $a->evaluation->recommendation->label(),
                    'comment' => $a->evaluation->comment,
                    'criteria_version' => $a->evaluation->criteria_version,
                    'submitted_at' => $a->evaluation->submitted_at?->toIso8601String(),
                ] : null,
            ])->values()->all(),
            // Per-criterion side-by-side rows (only criteria that were scored).
            'criteria' => $criteria,
            // The full active criteria set (web renders the scoring grid / modal from it).
            'active_criteria' => $active->map(fn (EvaluationCriterion $c): array => [
                'id' => $c->id, 'axis' => $c->axis->value, 'key' => $c->key, 'label' => $c->label(), 'label_ar' => $c->label_ar, 'label_en' => $c->label_en,
                'weight' => (float) $c->weight, 'scale_descriptions' => $c->scale_descriptions, 'version' => $c->version, 'is_active' => (bool) $c->is_active, 'sort' => $c->sort,
            ])->values()->all(),
            'criteria_version' => (int) ($active->max('version') ?? EvaluationCriterion::currentVersion()),
            // Divergences worth discussing (|delta| >= diff_threshold, setting `evaluation.score_diff_threshold`).
            'diff_threshold' => $threshold,
            'diffs' => array_values(array_map(fn (array $row): array => ['criterion_key' => $row['key'], 'delta' => $row['diff']], array_filter($criteria, fn (array $row): bool => (bool) $row['flag']))),
            'overall_diff' => $evaluations->count() >= 2 ? round(abs((float) $evaluations[0]->overall - (float) $evaluations[1]->overall), 2) : null,
            'recommendations_agree' => $evaluations->count() >= 2 ? $evaluations[0]->recommendation === $evaluations[1]->recommendation : null,
            'complete' => $evaluations->count() >= 2,
            'decision' => $decision ? [
                'result' => $decision->result->value,
                'result_label' => $decision->result->label(),
                'conditions' => $decision->conditions,
                'reasons' => $decision->reasons,
                'improvement_plan' => $decision->toOwnerArray()['improvement_plan'],
                'decided_by' => ['id' => $decision->decided_by, 'name' => $decision->decider?->name],
                'decided_at' => $decision->decided_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * EVL-03 — final decision by the supervisor (`evaluations.approve`). Sets opportunity.evaluation_result and tells
     * the owner. `$improvementPlan` (`[{text, due_at?}]`) is kept with "not eligible now" / "eligible with conditions".
     *
     * @param  array<int, array{text?: string|null, due_at?: string|null}>  $improvementPlan
     */
    public function decide(Opportunity $opportunity, User $actor, EvaluationResult $result, ?string $conditions, ?string $reasons, array $improvementPlan = []): EvaluationDecision
    {
        $plan = in_array($result, [EvaluationResult::NotEligibleNow, EvaluationResult::EligibleWithConditions], true)
            ? array_values(array_filter(array_map(fn ($item): ?array => is_array($item) && trim((string) ($item['text'] ?? '')) !== ''
                ? ['text' => trim((string) $item['text']), 'due_at' => ! empty($item['due_at']) ? (string) $item['due_at'] : null]
                : null, $improvementPlan)))
            : [];

        $scored = $opportunity->evaluationAssignments()->where('status', AssignmentStatus::Scored->value)->count();
        if ($scored < 2) {
            throw new DomainException(__('messages.evaluation.two_scores_required'), 'two_scores_required', 422);
        }
        if ($result === EvaluationResult::EligibleWithConditions && ($conditions === null || trim($conditions) === '')) {
            throw new DomainException(__('messages.evaluation.conditions_required'), 'conditions_required', 422);
        }
        if ($result === EvaluationResult::NotEligibleNow && ($reasons === null || trim($reasons) === '')) {
            throw new DomainException(__('messages.evaluation.reasons_required'), 'reasons_required', 422);
        }

        $decision = DB::transaction(function () use ($opportunity, $actor, $result, $conditions, $reasons, $plan): EvaluationDecision {
            $decision = EvaluationDecision::query()->create([
                'opportunity_id' => $opportunity->id,
                'result' => $result,
                'conditions' => $conditions,
                'reasons' => $reasons,
                'improvement_plan' => $plan ?: null,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            $before = ['evaluation_result' => $opportunity->evaluation_result?->value];
            $opportunity->evaluation_result = $result;
            $opportunity->evaluation_approved_at = now();
            $opportunity->save();

            $this->audit->log('evaluation.decided', $decision, $before, ['evaluation_result' => $result->value, 'improvement_plan_items' => count($plan)], $actor);

            return $decision;
        });

        $this->notifications->send($opportunity->owner, new EvaluationDecidedNotification($decision->setRelation('opportunity', $opportunity)));

        return $decision;
    }

    /**
     * @param  Collection<int, EvaluationCriterion>  $criteria
     * @param  array<string, array{score: int, note?: string|null}>  $scores
     * @return array<string, array{sum: int, count: int, weighted: float, max: float}>
     */
    private function axisTotals(Collection $criteria, array $scores): array
    {
        $totals = [];
        foreach (EvaluationAxis::cases() as $axis) {
            $totals[$axis->value] = ['sum' => 0, 'count' => 0, 'weighted' => 0.0, 'max' => 0.0];
        }

        foreach ($criteria as $criterion) {
            $axis = $criterion->axis->value;
            $score = (int) ($scores[$criterion->key]['score'] ?? 0);
            $weight = (float) $criterion->weight;
            $totals[$axis]['sum'] += $score;
            $totals[$axis]['count']++;
            $totals[$axis]['weighted'] += $score * $weight;
            $totals[$axis]['max'] += 5 * $weight;
        }

        foreach ($totals as $axis => $t) {
            // Normalise weighted total to a 0..5 scale so the overall is comparable across axes.
            $totals[$axis]['weighted'] = $t['max'] > 0 ? round($t['weighted'] / $t['max'] * 5, 2) : 0.0;
        }

        return $totals;
    }

    private function assertEvaluator(EvaluationAssignment $assignment, User $user): void
    {
        // ACC-04 — record-level: only the assigned evaluator may act on the assignment.
        if ($assignment->evaluator_user_id !== $user->id) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }
    }
}
