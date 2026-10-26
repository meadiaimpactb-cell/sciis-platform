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
        'password' => 'StrongPass123!', 'locale' => 'ar', 'consents' => ['privacy_policy', 'terms', 'data_accuracy'],
    ])->assertCreated()->assertJsonPath('data.type', 'investor_individual');

    $user = User::query()->where('email', 'new@investor.test')->firstOrFail();
    expect($user->membership)->not->toBeNull()->and($user->investorProfile)->not->toBeNull();
    $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'type' => 'privacy_policy']);
    $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'type' => 'terms']);
    $this->assertDatabaseCount('otp_codes', 2);
    Notification::assertSentTo($user, OtpNotification::class);
});

it('saves the investor profile sent with the registration', function (): void {
    Notification::fake();
    $sector = \App\Models\TaxonomyTerm::query()->group('sectors')->first();
    $stage = \App\Models\TaxonomyTerm::query()->group('project_stages')->first();
    $range = \App\Models\TaxonomyTerm::query()->group('investment_ranges')->first();

    $this->postJson('/api/v1/auth/register', [
        'type' => 'investor_institutional', 'name' => 'مكتب عائلي', 'email' => 'office@investor.test', 'phone' => '+966512345680',
        'password' => 'StrongPass123!', 'locale' => 'ar', 'consents' => ['privacy_policy', 'terms', 'data_accuracy'], 'consents_version' => '1.0',
        'organization_name' => 'مكتب الاستثمار العائلي', 'organization' => ['institution_type' => \App\Enums\InstitutionType::values()[0], 'city' => 'الرياض'],
        'preferred_contact' => 'whatsapp',
        'profile' => [
            'basics' => ['city' => 'الرياض', 'job_title' => 'شريك', 'employer' => 'المكتب'],
            'interests' => ['sector_ids' => [$sector->id], 'stage_ids' => [$stage->id], 'investment_range_id' => $range->id, 'risk_appetite' => 'medium'],
            'experience' => ['experience_years' => 7, 'experience_desc' => 'خبرة في الاستثمار الاجتماعي', 'prior_investments_count' => 3],
            'declarations' => ['conflicts_disclosure' => 'لا يوجد', 'commit_meetings' => true],
        ],
    ])->assertCreated()->assertJsonPath('data.membership.status', 'registered');

    $user = User::query()->where('email', 'office@investor.test')->firstOrFail();
    $profile = $user->investorProfile;
    expect($user->preferred_contact)->toBe('whatsapp')
        ->and($profile->city)->toBe('الرياض')
        ->and($profile->preferred_contact)->toBe('whatsapp')
        ->and($profile->experience_years)->toBe(7)
        ->and((int) $profile->investment_range_id)->toBe($range->id)
        ->and($profile->sectors()->pluck('taxonomy_terms.id')->all())->toBe([$sector->id])
        ->and($profile->stages()->pluck('taxonomy_terms.id')->all())->toBe([$stage->id])
        ->and($profile->completion_percent)->toBeGreaterThan(50)
        ->and($user->organization?->name)->toBe('مكتب الاستثمار العائلي')
        ->and($user->organization?->city)->toBe('الرياض');
});

it('creates the first draft opportunity sent with an entrepreneur registration', function (): void {
    Notification::fake();
    $sector = \App\Models\TaxonomyTerm::query()->group('sectors')->first();

    $this->postJson('/api/v1/auth/register', [
        'type' => 'entrepreneur_individual', 'name' => 'صاحب مشروع', 'email' => 'founder@owner.test', 'phone' => '+966512345681',
        'password' => 'StrongPass123!', 'consents' => ['privacy_policy', 'terms'],
        'project' => ['name' => 'مشروع التعليم', 'short_description' => 'وصف', 'sector_id' => $sector->id, 'stage' => 'seed'],
    ])->assertCreated();

    $user = User::query()->where('email', 'founder@owner.test')->firstOrFail();
    $opportunity = Opportunity::query()->where('owner_user_id', $user->id)->first();
    expect($opportunity)->not->toBeNull()
        ->and($opportunity->name)->toBe('مشروع التعليم')
        ->and($opportunity->status)->toBe(OpportunityStatus::Draft)
        ->and((int) $opportunity->sector_id)->toBe($sector->id);

    // A project without a name is rejected, the rest of the registration is not applied.
    $this->postJson('/api/v1/auth/register', [
        'type' => 'entrepreneur_individual', 'name' => 'صاحب مشروع', 'email' => 'founder2@owner.test', 'phone' => '+966512345682',
        'password' => 'StrongPass123!', 'consents' => ['privacy_policy', 'terms'], 'project' => ['stage' => 'seed'],
    ])->assertStatus(422);
});

it('exposes verification timestamps and the membership id on /me', function (): void {
    $user = $this->registeredInvestor();
    $this->actingAs($user, 'api');

    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('data.membership.id', $user->membership->id)
        ->assertJsonStructure(['data' => ['email_verified_at', 'phone_verified_at', 'two_factor_passed', 'unread_notifications']]);
});

it('requires both consents', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'type' => 'entrepreneur_individual', 'name' => 'صاحب مشروع', 'email' => 'e@owner.test', 'phone' => '+966512345679',
        'password' => 'StrongPass123!', 'consents' => ['terms'],
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
    $user = User::factory()->entrepreneur()->create(['password' => 'StrongPass123!']);

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'StrongPass123!', 'device_name' => 'iPhone'])
        ->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
});

it('prefers a cookie session over a token for stateful SPA logins', function (): void {
    $user = User::factory()->entrepreneur()->create(['password' => 'StrongPass123!']);

    $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/auth/login'])
        ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'StrongPass123!', 'device_name' => 'web'])
        ->assertOk()->assertJsonPath('data.two_factor_required', false)->assertJsonMissingPath('data.token');

    $this->assertAuthenticatedAs($user, 'web');
    expect($user->tokens()->count())->toBe(0);
});

it('returns the investor dashboard contract', function (): void {
    $this->actingAs($this->approvedInvestor(), 'api');

    $this->getJson('/api/v1/investor/dashboard')->assertOk()->assertJsonStructure([
        'data' => ['completion' => ['percent', 'missing_items'], 'requests', 'updates', 'membership_status', 'counters', 'suggested', 'next_meeting'],
    ]);
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
        'team' => ['founders_count' => 1, 'team_size' => 3, 'founders_commitment' => 'full_time'],
        'market' => ['product_stage' => 'mvp', 'target_customers' => 'عملاء', 'competitive_advantage' => 'ميزة'],
        'motivation' => ['why_now' => 'الآن'],
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
