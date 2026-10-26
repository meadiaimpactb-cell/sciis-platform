<?php

declare(strict_types=1);

use App\Enums\ConsentType;
use App\Enums\EvaluationResult;
use App\Enums\InterestStatus;
use App\Enums\OpportunityStatus;
use App\Events\OpportunityStatusChanged;
use App\Exceptions\InvalidTransitionException;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\Interest;
use App\Models\Opportunity;
use App\Models\Teaser;
use App\Services\OpportunityStateMachine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;

// ADM-01 — allowed map (SPEC §3)
it('allows the transitions in the spec map', function (string $from, string $to): void {
    $sm = app(OpportunityStateMachine::class);
    expect($sm->canTransition(OpportunityStatus::from($from), OpportunityStatus::from($to)))->toBeTrue();
})->with([
    ['draft', 'new'],
    ['new', 'under_review'],
    ['new', 'needs_completion'],
    ['new', 'not_eligible'],
    ['under_review', 'eligible'],
    ['under_review', 'needs_completion'],
    ['under_review', 'not_eligible'],
    ['needs_completion', 'under_review'],
    ['not_eligible', 'new'],
    ['eligible', 'connected'],
    ['connected', 'negotiating'],
    ['negotiating', 'in_execution'],
    ['in_execution', 'operating'],
    ['new', 'withdrawn'],
    ['eligible', 'withdrawn'],
    ['eligible', 'closed'],
    ['operating', 'closed'],
]);

it('forbids transitions outside the map', function (string $from, string $to): void {
    $sm = app(OpportunityStateMachine::class);
    expect($sm->canTransition(OpportunityStatus::from($from), OpportunityStatus::from($to)))->toBeFalse();
})->with([
    ['under_review', 'new'],        // explicitly forbidden (SPEC §3)
    ['draft', 'eligible'],
    ['new', 'eligible'],
    ['new', 'connected'],
    ['eligible', 'under_review'],
    ['connected', 'withdrawn'],     // owner withdrawal only before connected
    ['closed', 'new'],
    ['withdrawn', 'new'],
    ['closed', 'withdrawn'],
]);

it('submits a draft: generates reference number, sets SLA and writes transition + audit log + event', function (): void {
    Event::fake([OpportunityStatusChanged::class]);

    $owner = $this->entrepreneur();
    $opportunity = Opportunity::factory()->create(['owner_user_id' => $owner->id]);

    $result = app(OpportunityStateMachine::class)->transition($opportunity, OpportunityStatus::New, $owner, 'submit');

    expect($result->status)->toBe(OpportunityStatus::New)
        ->and($result->reference_no)->toMatch('/^A-\d{4}-\d{4}$/')
        ->and($result->submitted_at)->not->toBeNull()
        ->and($result->review_due_at)->not->toBeNull();

    $this->assertDatabaseHas('opportunity_status_transitions', [
        'opportunity_id' => $opportunity->id, 'from_status' => 'draft', 'to_status' => 'new', 'actor_user_id' => $owner->id,
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'opportunity.transition', 'auditable_type' => 'opportunity', 'auditable_id' => $opportunity->id, 'user_id' => $owner->id]);

    Event::assertDispatched(OpportunityStatusChanged::class, fn (OpportunityStatusChanged $e) => $e->to === OpportunityStatus::New && $e->from === OpportunityStatus::Draft);
});

it('rejects an invalid transition with InvalidTransitionException', function (): void {
    $owner = $this->entrepreneur();
    $opportunity = Opportunity::factory()->create(['owner_user_id' => $owner->id]);
    $admin = $this->staff('super_admin');

    app(OpportunityStateMachine::class)->transition($opportunity, OpportunityStatus::Eligible, $admin);
})->throws(InvalidTransitionException::class);

it('does not let an owner perform staff transitions', function (): void {
    $owner = $this->entrepreneur();
    $opportunity = Opportunity::factory()->status(OpportunityStatus::New)->create(['owner_user_id' => $owner->id]);

    app(OpportunityStateMachine::class)->transition($opportunity, OpportunityStatus::UnderReview, $owner);
})->throws(AuthorizationException::class);

it('requires a reason for not_eligible', function (): void {
    // ADM-07 — not_eligible is signed by the supervisor (evaluations.approve); the reason guard still applies.
    $supervisor = $this->staff('super_admin');
    $opportunity = Opportunity::factory()->status(OpportunityStatus::New)->create();

    app(OpportunityStateMachine::class)->transition($opportunity, OpportunityStatus::NotEligible, $supervisor, null);
})->throws(InvalidTransitionException::class);

it('does not let a reviewer sign not_eligible without the supervisor permission (ADM-07)', function (): void {
    $reviewer = $this->staff('reviewer');
    $opportunity = Opportunity::factory()->status(OpportunityStatus::New)->create();

    app(OpportunityStateMachine::class)->transition($opportunity, OpportunityStatus::NotEligible, $reviewer, 'خارج النطاق');
})->throws(AuthorizationException::class);

it('guards eligible with evaluation result and approved teaser', function (): void {
    $reviewer = $this->staff('reviewer');
    $opportunity = Opportunity::factory()->status(OpportunityStatus::UnderReview)->create();
    $sm = app(OpportunityStateMachine::class);

    expect(fn () => $sm->transition($opportunity, OpportunityStatus::Eligible, $reviewer))->toThrow(InvalidTransitionException::class);

    $opportunity->forceFill(['evaluation_result' => EvaluationResult::Eligible])->save();
    expect(fn () => $sm->transition($opportunity->fresh(), OpportunityStatus::Eligible, $reviewer))->toThrow(InvalidTransitionException::class);

    Teaser::query()->create(['opportunity_id' => $opportunity->id, 'summary_ar' => 'ملخص', 'version' => 1, 'approved_by_owner_at' => now(), 'approved_by_council_at' => now()]);
    // PRV-02: an approved teaser is not enough — the owner must have consented to its publication.
    expect(fn () => $sm->transition($opportunity->fresh(), OpportunityStatus::Eligible, $reviewer))->toThrow(InvalidTransitionException::class);

    Consent::query()->create([
        'user_id' => $opportunity->owner_user_id, 'type' => ConsentType::TeaserPublication, 'version' => '1.0',
        'text_hash' => hash('sha256', 'teaser_publication|1.0'), 'accepted_at' => now(),
        'subject_type' => $opportunity->getMorphClass(), 'subject_id' => $opportunity->id,
    ]);
    $result = $sm->transition($opportunity->fresh(), OpportunityStatus::Eligible, $reviewer);

    expect($result->status)->toBe(OpportunityStatus::Eligible);
});

it('guards connected with a connected interest', function (): void {
    $officer = $this->staff('matching_officer');
    $opportunity = Opportunity::factory()->eligible()->create();
    $sm = app(OpportunityStateMachine::class);

    expect(fn () => $sm->transition($opportunity, OpportunityStatus::Connected, $officer))->toThrow(InvalidTransitionException::class);

    Interest::factory()->create(['opportunity_id' => $opportunity->id, 'status' => InterestStatus::Connected]);
    expect($sm->transition($opportunity->fresh(), OpportunityStatus::Connected, $officer)->status)->toBe(OpportunityStatus::Connected);
});

it('writes an immutable audit log row that cannot be updated or deleted', function (): void {
    $owner = $this->entrepreneur();
    $opportunity = Opportunity::factory()->create(['owner_user_id' => $owner->id]);
    app(OpportunityStateMachine::class)->transition($opportunity, OpportunityStatus::New, $owner);

    $log = AuditLog::query()->where('action', 'opportunity.transition')->firstOrFail();

    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});
