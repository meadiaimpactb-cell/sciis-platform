<?php

declare(strict_types=1);

use App\Enums\EvaluationResult;
use App\Enums\OpportunityStatus;
use App\Models\EvaluationAssignment;
use App\Models\Opportunity;
use App\Services\EvaluationService;

/** ACC-04 / EVL-02 — record-level evaluator access & COI gate. */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->reviewer = $this->staff('reviewer');
    $this->evaluatorA = $this->staff('evaluator');
    $this->evaluatorB = $this->staff('evaluator');
    $this->outsider = $this->staff('evaluator');
    $this->opportunity = Opportunity::factory()->status(OpportunityStatus::UnderReview)->create();
});

it('assigns exactly two evaluators', function (): void {
    $this->actingAs($this->reviewer, 'api');

    $this->postJson("/api/v1/admin/opportunities/{$this->opportunity->uuid}/evaluation/assign", ['evaluator_ids' => [$this->evaluatorA->id]])->assertStatus(422);
    $this->postJson("/api/v1/admin/opportunities/{$this->opportunity->uuid}/evaluation/assign", ['evaluator_ids' => [$this->evaluatorA->id, $this->evaluatorB->id]])
        ->assertCreated()->assertJsonCount(2, 'data');
});

it('lets only the assigned evaluator see and act on the opportunity', function (): void {
    app(EvaluationService::class)->assign($this->opportunity, [$this->evaluatorA->id, $this->evaluatorB->id], $this->reviewer);
    $assignment = EvaluationAssignment::query()->where('evaluator_user_id', $this->evaluatorA->id)->firstOrFail();

    // Outsider evaluator: cannot view the opportunity nor the assignment.
    $this->actingAs($this->outsider, 'api');
    $this->getJson("/api/v1/admin/opportunities/{$this->opportunity->uuid}")->assertForbidden();
    $this->postJson("/api/v1/admin/evaluation-assignments/{$assignment->id}/coi", ['has_conflict' => false])->assertForbidden();
    $list = $this->getJson('/api/v1/admin/opportunities')->assertOk()->json('data');
    expect(collect($list)->pluck('uuid'))->not->toContain($this->opportunity->uuid);

    // Evaluator B cannot act on A's assignment.
    $this->actingAs($this->evaluatorB, 'api');
    $this->postJson("/api/v1/admin/evaluation-assignments/{$assignment->id}/coi", ['has_conflict' => false])->assertForbidden();

    // Evaluator A can.
    $this->actingAs($this->evaluatorA, 'api');
    $this->getJson("/api/v1/admin/opportunities/{$this->opportunity->uuid}")->assertOk();
    $this->postJson("/api/v1/admin/evaluation-assignments/{$assignment->id}/coi", ['has_conflict' => false])->assertOk();
});

it('enforces the COI gate before scoring and snapshots the criteria version', function (): void {
    app(EvaluationService::class)->assign($this->opportunity, [$this->evaluatorA->id, $this->evaluatorB->id], $this->reviewer);
    $assignment = EvaluationAssignment::query()->where('evaluator_user_id', $this->evaluatorA->id)->firstOrFail();

    $scores = app(EvaluationService::class)->activeCriteria()->mapWithKeys(fn ($c) => [$c->key => ['score' => 4, 'note' => null]])->all();

    $this->actingAs($this->evaluatorA, 'api');
    $this->postJson("/api/v1/admin/evaluation-assignments/{$assignment->id}/score", ['scores' => $scores, 'recommendation' => 'eligible'])->assertForbidden();

    $this->postJson("/api/v1/admin/evaluation-assignments/{$assignment->id}/coi", ['has_conflict' => false])->assertOk();
    $this->postJson("/api/v1/admin/evaluation-assignments/{$assignment->id}/score", ['scores' => $scores, 'recommendation' => 'eligible', 'comment' => 'جيد'])
        ->assertCreated()
        ->assertJsonPath('data.criteria_version', 1);

    $this->assertDatabaseHas('audit_logs', ['action' => 'evaluation.scored']);
});

it('declaring a conflict withdraws the evaluator', function (): void {
    app(EvaluationService::class)->assign($this->opportunity, [$this->evaluatorA->id, $this->evaluatorB->id], $this->reviewer);
    $assignment = EvaluationAssignment::query()->where('evaluator_user_id', $this->evaluatorA->id)->firstOrFail();

    $assignment = app(EvaluationService::class)->declareCoi($assignment, $this->evaluatorA, true);
    expect($assignment->status->value)->toBe('withdrawn')->and($assignment->canScore())->toBeFalse();
});

it('produces a side-by-side comparison with diffs and allows the decision only after two scores', function (): void {
    $service = app(EvaluationService::class);
    $service->assign($this->opportunity, [$this->evaluatorA->id, $this->evaluatorB->id], $this->reviewer);
    $criteria = $service->activeCriteria();
    $admin = $this->staff('super_admin');

    expect(fn () => $service->decide($this->opportunity, $admin, EvaluationResult::Eligible, null, null))->toThrow(\App\Exceptions\DomainException::class);

    foreach ([[$this->evaluatorA, 5], [$this->evaluatorB, 2]] as [$evaluator, $score]) {
        $assignment = EvaluationAssignment::query()->where('evaluator_user_id', $evaluator->id)->firstOrFail();
        $service->declareCoi($assignment, $evaluator, false);
        $service->score($assignment, $evaluator, $criteria->mapWithKeys(fn ($c) => [$c->key => ['score' => $score]])->all(), EvaluationResult::Eligible, null);
    }

    $comparison = $service->comparison($this->opportunity);
    expect($comparison['complete'])->toBeTrue()
        ->and($comparison['criteria'][0]['diff'])->toBe(3)
        ->and($comparison['criteria'][0]['flag'])->toBeTrue();

    $decision = $service->decide($this->opportunity, $admin, EvaluationResult::EligibleWithConditions, 'شروط', null);
    expect($decision->result)->toBe(EvaluationResult::EligibleWithConditions)
        ->and($this->opportunity->fresh()->evaluation_result)->toBe(EvaluationResult::EligibleWithConditions);
});
