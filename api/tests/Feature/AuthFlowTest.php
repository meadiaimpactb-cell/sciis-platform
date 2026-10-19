<?php

declare(strict_types=1);

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\OtpNotification;
use Illuminate\Support\Facades\Notification;

it('registers an investor, records consents and issues OTPs', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'type' => 'investor_individual', 'name' => 'مستثمر تجريبي', 'email' => 'new@investor.test', 'phone' => '+966512345678',
        'password' => 'StrongPass123', 'locale' => 'ar', 'consents' => ['privacy_policy', 'terms'],
    ])->assertCreated()->assertJsonPath('data.type', 'investor_individual');

    $user = User::query()->where('email', 'new@investor.test')->firstOrFail();
    expect($user->membership)->not->toBeNull()->and($user->investorProfile)->not->toBeNull();
    $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'type' => 'privacy_policy']);
    $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'type' => 'terms']);
    $this->assertDatabaseCount('otp_codes', 2);
    Notification::assertSentTo($user, OtpNotification::class);
});

it('requires both consents', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'type' => 'entrepreneur_individual', 'name' => 'صاحب مشروع', 'email' => 'e@owner.test', 'phone' => '+966512345679',
        'password' => 'StrongPass123', 'consents' => ['terms'],
    ])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('blocks unverified users from verified routes', function (): void {
    $user = User::factory()->entrepreneur()->unverified()->create();
    $this->actingAs($user, 'api');

    $this->getJson('/api/v1/entrepreneur/dashboard')->assertForbidden()->assertJsonPath('code', 'verification_required');
});

it('blocks non-approved investors from member routes', function (): void {
    $this->actingAs($this->registeredInvestor(), 'api');
    $this->getJson('/api/v1/opportunities')->assertForbidden()->assertJsonPath('code', 'membership_required');
});

it('enforces 2FA enrolment for staff when enabled', function (): void {
    config(['sciis.two_factor.enforce_for_staff' => true]);
    $this->actingAs($this->staff('reviewer'), 'api');

    $this->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_enrolment_required');
});

it('issues a mobile token on login with device_name', function (): void {
    $user = User::factory()->entrepreneur()->create(['password' => 'StrongPass123']);

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'StrongPass123', 'device_name' => 'iPhone'])
        ->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
});

it('entrepreneur can create, complete and submit an opportunity and gets a reference number', function (): void {
    $owner = $this->entrepreneur();
    $this->actingAs($owner, 'api');

    $uuid = $this->postJson('/api/v1/entrepreneur/opportunities', ['name' => 'مشروعي التجريبي'])->assertCreated()->json('data.uuid');

    // Checklist incomplete → submit refused.
    $this->postJson("/api/v1/entrepreneur/opportunities/{$uuid}/submit")->assertStatus(422)->assertJsonPath('code', 'checklist_incomplete');

    $sector = \App\Models\TaxonomyTerm::query()->group('sectors')->first();
    $region = \App\Models\TaxonomyTerm::query()->group('regions')->first();
    $type = \App\Models\TaxonomyTerm::query()->group('investment_types')->first();

    $this->patchJson("/api/v1/entrepreneur/opportunities/{$uuid}", [
        'basics' => ['short_description' => 'وصف', 'sector_id' => $sector->id, 'stage' => 'seed', 'region_ids' => [$region->id], 'investment_type_ids' => [$type->id]],
        'impact' => ['problem_statement' => 'مشكلة', 'solution' => 'حل', 'expected_change' => 'تغيير', 'beneficiaries_desc' => 'مستفيدون', 'beneficiaries_target' => 100],
        'financials' => ['revenue_state' => 'none', 'funding_amount' => 500000, 'use_of_funds' => [['item' => 'أ', 'percent' => 100]]],
        'disclosures' => ['data_accuracy_declared' => true],
    ])->assertOk();

    $this->postJson("/api/v1/entrepreneur/opportunities/{$uuid}/indicators", ['name' => 'مؤشر', 'is_primary' => true, 'target' => 100])->assertCreated();

    $opportunity = Opportunity::query()->where('uuid', $uuid)->firstOrFail();
    \App\Models\Document::factory()->forOpportunity($opportunity)->create(['kind' => 'pitch_deck']);

    $this->postJson("/api/v1/entrepreneur/opportunities/{$uuid}/submit")->assertOk()->assertJsonPath('data.status', OpportunityStatus::New->value);
    expect($opportunity->fresh()->reference_no)->toMatch('/^A-\d{4}-0001$/');

    // Locked after submission (ENT-05).
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$uuid}", ['basics' => ['name' => 'x']])->assertForbidden();
});
