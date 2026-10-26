<?php

declare(strict_types=1);

use App\Enums\DocumentKind;
use App\Models\Document;
use App\Models\FileAccessGrant;
use App\Models\Opportunity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** DOC-03 (version history: uploader + date + previous version) and IMP-03 (IRIS+ code on indicators). */
beforeEach(function (): void {
    Storage::fake('local');
    $this->owner = $this->entrepreneur();
    $this->opportunity = Opportunity::factory()->create(['owner_user_id' => $this->owner->id]);
});

it('exposes uploader, upload date and the previous version to the owner and staff — not to investors', function (): void {
    $this->actingAs($this->owner, 'api');
    $upload = fn (string $name) => $this->post("/api/v1/entrepreneur/opportunities/{$this->opportunity->uuid}/documents", [
        'kind' => 'pitch_deck', 'file' => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n{$name}"),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');

    $v1 = $upload('deck-v1.pdf');
    $v2 = $upload('deck-v2.pdf');

    $docs = collect($this->getJson("/api/v1/entrepreneur/opportunities/{$this->opportunity->uuid}")->assertOk()->json('data.documents'));
    $latest = $docs->firstWhere('uuid', $v2['uuid']);
    expect($latest['version'])->toBe(2)
        ->and($latest['previous_version_uuid'])->toBe($v1['uuid'])
        ->and($latest['uploaded_by'])->toBe(['name' => $this->owner->name, 'is_staff' => false])
        ->and($latest['uploaded_at'])->not->toBeNull()
        ->and($docs->firstWhere('uuid', $v1['uuid'])['previous_version_uuid'])->toBeNull();

    // the owner opens an older version through the own-documents route (access logged)
    $this->getJson("/api/v1/me/documents/{$v1['uuid']}/download-url")->assertOk();
    $this->assertDatabaseHas('document_access_logs', ['document_id' => Document::query()->where('uuid', $v1['uuid'])->value('id'), 'user_id' => $this->owner->id]);

    // a version added by staff: staff see the name, the owner sees "SCIIS team"
    $staff = $this->staff('reviewer');
    Document::factory()->forOpportunity($this->opportunity)->create(['owner_user_id' => $staff->id, 'kind' => DocumentKind::Other, 'title' => 'محضر']);
    $ownerView = collect($this->getJson("/api/v1/entrepreneur/opportunities/{$this->opportunity->uuid}")->json('data.documents'))->firstWhere('title', 'محضر');
    expect($ownerView['uploaded_by'])->toBe(['name' => __('messages.documents.council_team'), 'is_staff' => true]);

    $this->actingAs($staff, 'api');
    $staffView = collect($this->getJson("/api/v1/admin/opportunities/{$this->opportunity->uuid}")->assertOk()->json('data.documents'));
    expect($staffView->firstWhere('title', 'محضر')['uploaded_by']['name'])->toBe($staff->name)
        ->and($staffView->firstWhere('uuid', $v2['uuid'])['uploaded_by']['name'])->toBe($this->owner->name);

    // granted investor: documents listed without uploader identity or version links
    $this->opportunity->update(['status' => 'eligible']);
    $investor = $this->approvedInvestor();
    FileAccessGrant::query()->create(['opportunity_id' => $this->opportunity->id, 'investor_user_id' => $investor->id, 'granted_by_owner_at' => now()]);
    $consent = $investor->consents()->create(['type' => 'nda', 'version' => '1.0', 'text_hash' => str_repeat('a', 64), 'accepted_at' => now(), 'subject_type' => 'opportunity', 'subject_id' => $this->opportunity->id]);
    FileAccessGrant::query()->where('investor_user_id', $investor->id)->update(['nda_consent_id' => $consent->id]);

    $this->actingAs($investor, 'api');
    $seen = $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/documents")->assertOk()->json('data');
    expect($seen)->not->toBeEmpty();
    foreach ($seen as $doc) {
        expect($doc)->not->toHaveKey('uploaded_by')->and($doc)->not->toHaveKey('previous_version_uuid');
    }
    // and the own-documents route is not a way around the grant rules
    $this->getJson("/api/v1/me/documents/{$v1['uuid']}/download-url")->assertForbidden();
});

it('accepts, normalises, validates and returns the IRIS+ code of an indicator', function (): void {
    $this->actingAs($this->owner, 'api');
    $base = "/api/v1/entrepreneur/opportunities/{$this->opportunity->uuid}/indicators";

    $this->postJson($base, ['name' => 'عدد المستفيدين', 'iris_code' => ' pi 4060 ', 'is_primary' => true])
        ->assertCreated()->assertJsonPath('data.iris_code', 'PI4060');
    $this->postJson($base, ['name' => 'الوظائف المستحدثة', 'iris_code' => 'OI1479'])->assertCreated()->assertJsonPath('data.iris_code', 'OI1479');
    $this->postJson($base, ['name' => 'بدون رمز', 'iris_code' => ''])->assertCreated()->assertJsonPath('data.iris_code', null);

    foreach (['XX1234', 'PI12', 'PI12345', 'IRIS-PI1234'] as $bad) {
        $this->postJson($base, ['name' => 'مؤشر خاطئ', 'iris_code' => $bad])->assertStatus(422)->assertJsonValidationErrors(['iris_code']);
    }

    $codes = fn (array $rows): array => collect($rows)->pluck('iris_code', 'name')->sortKeys()->all();
    $expected = ['الوظائف المستحدثة' => 'OI1479', 'بدون رمز' => null, 'عدد المستفيدين' => 'PI4060'];
    expect($codes($this->getJson($base)->assertOk()->json('data')))->toBe($expected)
        ->and($codes($this->getJson("/api/v1/entrepreneur/opportunities/{$this->opportunity->uuid}")->json('data.indicators')))->toBe($expected);
});
