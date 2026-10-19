<?php

declare(strict_types=1);

use App\Enums\InterestStatus;
use App\Enums\OwnerDecision;
use App\Models\Document;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Services\InterestService;
use App\Services\SettingsService;

/** ENT-06 / OPP-05 — investor cannot read documents before owner approval AND NDA. */
beforeEach(function (): void {
    $this->owner = $this->entrepreneur();
    $this->opportunity = Opportunity::factory()->eligible()->create(['owner_user_id' => $this->owner->id]);
    $this->document = Document::factory()->forOpportunity($this->opportunity)->create();
    $this->investor = $this->approvedInvestor();

    Nomination::query()->create([
        'opportunity_id' => $this->opportunity->id, 'investor_user_id' => $this->investor->id, 'match_score' => 2, 'matched_criteria' => ['sector', 'stage'], 'sent_at' => now(),
    ]);
});

it('blocks documents and download URLs before any consent', function (): void {
    $this->actingAs($this->investor, 'api');

    $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/documents")->assertForbidden();
    $this->getJson("/api/v1/documents/{$this->document->uuid}/download-url")->assertForbidden();
});

it('runs the dual consent flow: interest → owner approval → NDA → documents', function (): void {
    // 1. Investor expresses interest.
    $this->actingAs($this->investor, 'api');
    $this->postJson("/api/v1/opportunities/{$this->opportunity->uuid}/interest", ['note' => 'مهتم'])
        ->assertCreated()
        ->assertJsonPath('data.status', InterestStatus::AwaitingOwnerApproval->value);

    $interest = $this->opportunity->interests()->firstOrFail();

    // Still blocked.
    $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/documents")->assertForbidden();

    // 2. Owner approves — but NDA not yet accepted → still blocked.
    $this->actingAs($this->owner, 'api');
    $response = $this->postJson("/api/v1/entrepreneur/access-requests/{$interest->id}/decide", ['decision' => 'approve'])->assertOk();
    expect($response->json('data.status'))->toBe(InterestStatus::Shared->value)
        ->and($response->json('data'))->not->toHaveKey('investor'); // MSG-01: identity withheld from owner

    $this->actingAs($this->investor, 'api');
    $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/documents")->assertForbidden();
    $this->getJson("/api/v1/documents/{$this->document->uuid}/download-url")->assertForbidden();

    // 3. NDA accepted → grant active → documents visible + signed URL + access log.
    $this->postJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda-accept", ['version' => '1.0'])->assertOk()->assertJsonPath('data.granted', true);
    $this->assertDatabaseHas('consents', ['user_id' => $this->investor->id, 'type' => 'nda', 'subject_id' => $this->opportunity->id]);

    $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/documents")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/documents/{$this->document->uuid}/download-url")->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']]);
    $this->assertDatabaseHas('document_access_logs', ['document_id' => $this->document->id, 'user_id' => $this->investor->id, 'action' => 'download']);
});

it('NDA cannot be accepted before owner approval', function (): void {
    $this->actingAs($this->investor, 'api');
    app(InterestService::class)->express($this->opportunity, $this->investor);

    $this->postJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda-accept", ['version' => '1.0'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'owner_approval_pending');
});

it('owner decline closes the interest and revokes nothing further', function (): void {
    $interest = app(InterestService::class)->express($this->opportunity, $this->investor);
    $interest = app(InterestService::class)->ownerDecide($interest, $this->owner, OwnerDecision::Declined, 'ليس الآن');

    expect($interest->status)->toBe(InterestStatus::Closed)
        ->and($this->opportunity->hasActiveGrantFor($this->investor))->toBeFalse();
});

it('does not show hidden-status opportunities to investors even with browse_all', function (): void {
    app(SettingsService::class)->set('opportunity.access_policy', 'browse_all');
    $hidden = Opportunity::factory()->status(\App\Enums\OpportunityStatus::UnderReview)->create();

    $this->actingAs($this->investor, 'api');
    $this->getJson("/api/v1/opportunities/{$hidden->uuid}")->assertForbidden();
    $list = $this->getJson('/api/v1/opportunities')->assertOk()->json('data');
    expect(collect($list)->pluck('uuid'))->not->toContain($hidden->uuid)->toContain($this->opportunity->uuid);
});
