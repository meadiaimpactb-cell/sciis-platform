<?php

declare(strict_types=1);

use App\Enums\InstitutionType;
use App\Enums\MembershipStatus;
use App\Enums\UserType;
use App\Models\Document;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\TaxonomyTerm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** INV-01 (membership file uploads, impact policy, data-accuracy declaration) + INV-02 (last updated). */
beforeEach(function (): void {
    Storage::fake('local');
    $this->pdf = fn (string $name = 'file.pdf') => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$name.' '.str_repeat('x', 200));
    $this->upload = fn (string $kind, string $name = 'file.pdf') => $this->post('/api/v1/investor/profile/documents', ['kind' => $kind, 'file' => ($this->pdf)($name)], ['Accept' => 'application/json']);
});

function institutionalInvestor(): User
{
    $user = User::factory()->investor()->create(['type' => UserType::InvestorInstitutional]);
    $org = Organization::query()->create(['name' => 'صندوق الأثر', 'institution_type' => InstitutionType::values()[0], 'representative_user_id' => $user->id]);
    $user->forceFill(['organization_id' => $org->id])->save();

    return $user->refresh();
}

it('lets an individual investor upload a CV through the secure pipeline (versions kept)', function (): void {
    $investor = $this->registeredInvestor();
    $this->actingAs($investor, 'api');

    $first = ($this->upload)('investor_cv', 'cv-2025.pdf')->assertCreated()->assertJsonPath('data.kind', 'investor_cv')->assertJsonPath('data.version', 1)->json('data');
    $second = ($this->upload)('investor_cv', 'cv-2026.pdf')->assertCreated()->assertJsonPath('data.version', 2)->json('data');
    expect($second['previous_version_uuid'])->toBe($first['uuid'])
        ->and($second['uploaded_by']['name'])->toBe($investor->name);

    $doc = Document::query()->where('uuid', $second['uuid'])->firstOrFail();
    expect($doc->encrypted)->toBeTrue()
        ->and($doc->documentable_type)->toBe('investor_profile')
        ->and((int) $investor->investorProfile->refresh()->cv_document_id)->toBe($doc->id);

    $profile = $this->getJson('/api/v1/investor/profile')->assertOk()->json('data');
    expect($profile['experience']['cv_document_uuid'])->toBe($second['uuid'])
        ->and(collect($profile['documents'])->pluck('uuid')->all())->toBe([$second['uuid'], $first['uuid']])
        ->and($profile['updated_at'])->not->toBeNull();

    // institution-only kinds are refused for individuals
    ($this->upload)('commercial_register')->assertStatus(422)->assertJsonValidationErrors(['kind']);
    // size / type limits come from the settings (DocumentService)
    $this->post('/api/v1/investor/profile/documents', ['kind' => 'investor_cv', 'file' => UploadedFile::fake()->createWithContent('cv.html', '<html><script>x</script></html>')], ['Accept' => 'application/json'])
        ->assertStatus(422);

    $this->assertDatabaseHas('audit_logs', ['action' => 'investor_profile.document_uploaded', 'user_id' => $investor->id]);
});

it('collects the commercial register, authorization letter and impact policy from an institution', function (): void {
    $investor = institutionalInvestor();
    $this->actingAs($investor, 'api');

    ($this->upload)('commercial_register', 'cr.pdf')->assertCreated();
    $letter = ($this->upload)('authorization_letter', 'letter.pdf')->assertCreated()->json('data.uuid');
    ($this->upload)('impact_policy', 'esg.pdf')->assertCreated();
    ($this->upload)('investor_cv')->assertStatus(422)->assertJsonValidationErrors(['kind']);

    expect($investor->organization->refresh()->authorization_letter_document_id)->toBe(Document::query()->where('uuid', $letter)->value('id'));

    $this->putJson('/api/v1/investor/profile', ['experience' => ['impact_policy' => 'نلتزم بمعايير الاستثمار المسؤول ومبادئ ESG.']])->assertOk()
        ->assertJsonPath('data.experience.impact_policy', 'نلتزم بمعايير الاستثمار المسؤول ومبادئ ESG.');

    $kinds = collect($this->getJson('/api/v1/investor/profile')->json('data.documents'))->pluck('kind')->sort()->values()->all();
    expect($kinds)->toBe(['authorization_letter', 'commercial_register', 'impact_policy']);

    // the impact policy text is ignored for an individual investor
    $individual = $this->registeredInvestor();
    $this->actingAs($individual, 'api');
    $this->putJson('/api/v1/investor/profile', ['experience' => ['impact_policy' => 'x']])->assertOk();
    expect($individual->investorProfile->refresh()->impact_policy)->toBeNull();
});

it('opens membership files to the owner and membership reviewers only', function (): void {
    $investor = institutionalInvestor();
    $this->actingAs($investor, 'api');
    $uuid = ($this->upload)('commercial_register', 'cr.pdf')->assertCreated()->json('data.uuid');

    // owner (own download route) → signed URL that streams the decrypted file
    $url = $this->getJson("/api/v1/me/documents/{$uuid}/download-url")->assertOk()->json('data.url');
    expect($this->get($url)->assertOk()->streamedContent())->toStartWith('%PDF-1.4');

    // membership officer reviewing the application
    $officer = $this->staff('membership_officer');
    $this->actingAs($officer, 'api');
    $this->getJson("/api/v1/admin/documents/{$uuid}/download-url")->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']]);
    $this->assertDatabaseHas('document_access_logs', ['user_id' => $officer->id, 'action' => 'download']);

    $membership = Membership::query()->where('user_id', $investor->id)->firstOrFail();
    $membership->forceFill(['status' => MembershipStatus::UnderReview, 'submitted_at' => now()])->save();
    $shown = $this->getJson("/api/v1/admin/memberships/{$membership->id}")->assertOk()->json('data.profile.documents');
    expect($shown[0]['uuid'])->toBe($uuid)->and($shown[0]['uploaded_by']['name'])->toBe($investor->name);

    // other staff (no memberships.review), other investors, entrepreneurs → refused
    $this->actingAs($this->staff('reviewer'), 'api');
    $this->getJson("/api/v1/admin/documents/{$uuid}/download-url")->assertForbidden();

    $other = $this->approvedInvestor();
    $this->actingAs($other, 'api');
    $this->getJson("/api/v1/me/documents/{$uuid}/download-url")->assertForbidden();
    $this->getJson("/api/v1/documents/{$uuid}/download-url")->assertForbidden();
});

it('requires, stores and exposes the data-accuracy declaration at registration', function (): void {
    Notification::fake();
    $payload = [
        'type' => 'investor_individual', 'name' => 'مستثمر دقيق', 'email' => 'accurate@investor.test', 'phone' => '+966512345690',
        'password' => 'StrongPass123!', 'locale' => 'ar', 'consents' => ['privacy_policy', 'terms'],
    ];

    $this->postJson('/api/v1/auth/register', $payload)->assertStatus(422)->assertJsonValidationErrors(['consents.data_accuracy']);

    $this->postJson('/api/v1/auth/register', ['consents' => ['privacy_policy', 'terms', 'data_accuracy']] + $payload)->assertCreated();
    $user = User::query()->where('email', 'accurate@investor.test')->firstOrFail();
    $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'type' => 'data_accuracy', 'version' => '1.0']);

    $user->forceFill(['email_verified_at' => now(), 'phone_verified_at' => now()])->save();
    $this->actingAs($user, 'api');
    expect($this->getJson('/api/v1/investor/profile')->json('data.declarations.data_accuracy_declared_at'))->not->toBeNull();

    // entrepreneurs declare accuracy on the opportunity itself — not required at registration
    $this->postJson('/api/v1/auth/register', [
        'type' => 'entrepreneur_individual', 'name' => 'صاحب مشروع', 'email' => 'owner@x.test', 'phone' => '+966512345691',
        'password' => 'StrongPass123!', 'consents' => ['privacy_policy', 'terms'],
    ])->assertCreated();
});

it('moves "last updated" whenever a profile section is saved', function (): void {
    $investor = $this->registeredInvestor();
    $this->actingAs($investor, 'api');
    $this->travelTo(now()->subDay());
    $investor->investorProfile->touch();
    $this->travelBack();

    $before = $this->getJson('/api/v1/investor/profile')->json('data.updated_at');
    $sector = TaxonomyTerm::query()->group('sectors')->firstOrFail();
    $after = $this->putJson('/api/v1/investor/profile', ['interests' => ['sector_ids' => [$sector->id]]])->assertOk()->json('data.updated_at');

    expect(strtotime($after))->toBeGreaterThan(strtotime($before));
});
