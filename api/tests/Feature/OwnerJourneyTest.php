<?php

declare(strict_types=1);

use App\Enums\ConsentType;
use App\Enums\EvaluationResult;
use App\Enums\InterestStatus;
use App\Enums\OpportunityStatus;
use App\Enums\OwnerDecision;
use App\Models\Consent;
use App\Models\Document;
use App\Models\EvaluationAssignment;
use App\Models\FileAccessGrant;
use App\Models\Interest;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Notifications\AccessRevokedNotification;
use App\Notifications\EvaluationDecidedNotification;
use App\Services\DocumentService;
use App\Services\EvaluationService;
use App\Services\InterestService;
use App\Services\OpportunityStateMachine;
use Illuminate\Support\Facades\Notification;

/** ENT-04 (owner timeline), ENT-06 (owner revokes access), EVL-03 (decision + improvement plan shown to the owner). */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->owner = $this->entrepreneur();
});

/** Owner approved + NDA accepted → an active grant (the NDA consent is written directly to keep the test on ENT-06). */
function ownerJourneyGrantedInterest(Opportunity $opportunity, $owner, $investor): Interest
{
    Nomination::query()->create(['opportunity_id' => $opportunity->id, 'investor_user_id' => $investor->id, 'match_score' => 2, 'matched_criteria' => ['sector'], 'sent_at' => now()]);
    $interest = app(InterestService::class)->express($opportunity, $investor);
    $interest = app(InterestService::class)->ownerDecide($interest, $owner, OwnerDecision::Approved);

    $consent = Consent::query()->create([
        'user_id' => $investor->id, 'type' => ConsentType::Nda, 'version' => '1.0', 'text_hash' => hash('sha256', 'nda'),
        'accepted_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'pest', 'subject_type' => $opportunity->getMorphClass(), 'subject_id' => $opportunity->id,
    ]);
    FileAccessGrant::query()->where('opportunity_id', $opportunity->id)->where('investor_user_id', $investor->id)->update(['nda_consent_id' => $consent->id]);

    return $interest->refresh();
}

it('exposes the status timeline and completion requests to the owner (ENT-04)', function (): void {
    $opportunity = Opportunity::factory()->status(OpportunityStatus::Draft)->create(['owner_user_id' => $this->owner->id]);
    $reviewer = $this->staff('reviewer');
    $sm = app(OpportunityStateMachine::class);
    $sm->transition($opportunity, OpportunityStatus::New, $this->owner, 'تقديم');
    $sm->transition($opportunity->refresh(), OpportunityStatus::UnderReview, $reviewer);

    $this->actingAs($this->owner, 'api');
    $data = $this->getJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}")->assertOk()->json('data');

    expect($data['timeline'])->toHaveCount(2)
        ->and($data['timeline'][0]['to_status'])->toBe('new')
        ->and($data['timeline'][1]['to_status'])->toBe('under_review')
        // Staff identities are never shown to the owner.
        ->and($data['timeline'][1]['actor_name'])->toBe(__('messages.council'))
        ->and($data)->toHaveKey('completion_requests');
});

it('lets the owner revoke an approved access: documents close at once, interest closed, investor + staff notified (ENT-06)', function (): void {
    Notification::fake();
    $opportunity = Opportunity::factory()->eligible()->create(['owner_user_id' => $this->owner->id]);
    Document::factory()->forOpportunity($opportunity)->create();
    $investor = $this->approvedInvestor();
    $matching = $this->staff('matching_officer');
    $interest = ownerJourneyGrantedInterest($opportunity, $this->owner, $investor);

    $this->actingAs($investor, 'api');
    $this->getJson("/api/v1/opportunities/{$opportunity->uuid}/documents")->assertOk();
    // A download link handed out before the revoke…
    $link = app(DocumentService::class)->temporaryUrl(Document::query()->where('documentable_id', $opportunity->id)->firstOrFail(), $investor)['url'];

    // The owner sees the grant as active and revocable.
    $this->actingAs($this->owner, 'api');
    $row = collect($this->getJson('/api/v1/entrepreneur/access-requests')->assertOk()->json('data'))->firstWhere('id', $interest->id);
    expect($row['access_active'])->toBeTrue()->and($row['can_revoke'])->toBeTrue();

    $this->postJson("/api/v1/entrepreneur/access-requests/{$interest->id}/revoke", ['reason' => 'انتهت المناقشات'])
        ->assertOk()
        ->assertJsonPath('data.status', InterestStatus::Closed->value)
        ->assertJsonPath('data.access_active', false);

    expect($opportunity->hasActiveGrantFor($investor))->toBeFalse()
        ->and(FileAccessGrant::query()->where('opportunity_id', $opportunity->id)->where('investor_user_id', $investor->id)->value('revoked_at'))->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'interest.access_revoked', 'auditable_id' => $interest->id, 'user_id' => $this->owner->id]);

    Notification::assertSentTo($investor, AccessRevokedNotification::class);
    Notification::assertSentTo($matching, AccessRevokedNotification::class);

    // Investor loses document access immediately.
    $this->actingAs($investor, 'api');
    $this->getJson("/api/v1/opportunities/{$opportunity->uuid}/documents")->assertForbidden();
    // …stops working at once (the stream re-checks access for the user the link was issued to).
    $this->get($link)->assertForbidden();

    // A second revoke has nothing left to revoke.
    $this->actingAs($this->owner, 'api');
    $this->postJson("/api/v1/entrepreneur/access-requests/{$interest->id}/revoke")->assertStatus(422)->assertJsonPath('code', 'grant_not_active');
});

it('refuses a revoke by anyone but the owner, and before the owner approved (ENT-06)', function (): void {
    $opportunity = Opportunity::factory()->eligible()->create(['owner_user_id' => $this->owner->id]);
    $investor = $this->approvedInvestor();
    $interest = ownerJourneyGrantedInterest($opportunity, $this->owner, $investor);

    $this->actingAs($this->entrepreneur(), 'api');
    $this->postJson("/api/v1/entrepreneur/access-requests/{$interest->id}/revoke")->assertForbidden();

    $this->actingAs($investor, 'api');
    $this->postJson("/api/v1/entrepreneur/access-requests/{$interest->id}/revoke")->assertForbidden();

    $pending = Interest::factory()->create(['opportunity_id' => $opportunity->id]);
    $this->actingAs($this->owner, 'api');
    $this->postJson("/api/v1/entrepreneur/access-requests/{$pending->id}/revoke")->assertStatus(422)->assertJsonPath('code', 'grant_not_active');
});

it('stores an improvement plan with the decision and shows the decision to the owner (EVL-03)', function (): void {
    Notification::fake();
    $opportunity = Opportunity::factory()->status(OpportunityStatus::UnderReview)->create(['owner_user_id' => $this->owner->id]);
    $service = app(EvaluationService::class);
    $reviewer = $this->staff('reviewer');
    $evaluators = [$this->staff('evaluator'), $this->staff('evaluator')];
    $service->assign($opportunity, [$evaluators[0]->id, $evaluators[1]->id], $reviewer);
    $scores = $service->activeCriteria()->mapWithKeys(fn ($c) => [$c->key => ['score' => 2]])->all();
    foreach ($evaluators as $evaluator) {
        $assignment = EvaluationAssignment::query()->where('evaluator_user_id', $evaluator->id)->firstOrFail();
        $service->declareCoi($assignment, $evaluator, false);
        $service->score($assignment->refresh(), $evaluator, $scores, EvaluationResult::NotEligibleNow, null);
    }

    $admin = $this->staff('super_admin');
    $this->actingAs($admin, 'api');
    $due = now()->addMonth()->toDateString();

    // Plan items are validated (text required).
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/evaluation/decide", [
        'result' => 'not_eligible_now', 'reasons' => 'نموذج الإيرادات غير واضح', 'improvement_plan' => [['text' => '']],
    ])->assertStatus(422)->assertJsonValidationErrors(['improvement_plan.0.text']);
    // …and refused with a plain "eligible".
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/evaluation/decide", [
        'result' => 'eligible', 'improvement_plan' => [['text' => 'بند']],
    ])->assertStatus(422)->assertJsonValidationErrors(['improvement_plan']);

    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/evaluation/decide", [
        'result' => 'not_eligible_now',
        'reasons' => 'نموذج الإيرادات غير واضح',
        'improvement_plan' => [['text' => 'توضيح نموذج الإيرادات', 'due_at' => $due], ['text' => 'إضافة مؤشر أثر قابل للقياس']],
    ])->assertCreated()->assertJsonCount(2, 'data.improvement_plan');

    Notification::assertSentTo($this->owner, EvaluationDecidedNotification::class);

    // Staff panel carries the plan.
    $panel = $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}/evaluation")->assertOk()->json('data.decision');
    expect($panel['improvement_plan'])->toHaveCount(2);

    // The owner sees result, reasons, plan and date — on the opportunity and on the dashboard.
    $this->actingAs($this->owner, 'api');
    $decision = $this->getJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}")->assertOk()->json('data.evaluation_decision');
    expect($decision['result'])->toBe('not_eligible_now')
        ->and($decision['reasons'])->toBe('نموذج الإيرادات غير واضح')
        ->and($decision['improvement_plan'][0])->toBe(['text' => 'توضيح نموذج الإيرادات', 'due_at' => $due])
        ->and($decision['improvement_plan'][1]['due_at'])->toBeNull()
        ->and($decision['decided_at'])->not->toBeNull()
        ->and($decision)->not->toHaveKey('decided_by');

    $row = collect($this->getJson('/api/v1/entrepreneur/dashboard')->assertOk()->json('data.opportunities'))->firstWhere('uuid', $opportunity->uuid);
    expect($row['evaluation_decision']['result'])->toBe('not_eligible_now');
});
