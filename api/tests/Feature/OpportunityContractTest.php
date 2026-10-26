<?php

declare(strict_types=1);

use App\Enums\OpportunityStatus;
use App\Models\CompletionRequest;
use App\Models\Document;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;

/**
 * Contract audit (#1, #2, #6, #9, #10, #11, #12, #13, #14, #15): the shapes the web/mobile clients read
 * from the opportunity endpoints, plus the ENT-05 field-level lock while `needs_completion`.
 */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->owner = $this->entrepreneur();
});

$term = fn (string $group) => TaxonomyTerm::query()->where('group', $group)->active()->ordered()->firstOrFail();

it('creates a draft from an empty payload with a localized default name (#10)', function (): void {
    $this->actingAs($this->owner, 'api');

    $this->postJson('/api/v1/entrepreneur/opportunities', [])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.name', __('messages.opportunity.default_name'))
        ->assertJsonPath('data.locked_sections', [])
        ->assertJsonPath('data.region_ids', []);

    $this->postJson('/api/v1/entrepreneur/opportunities', ['name' => 'مشروعي'])->assertCreated()->assertJsonPath('data.name', 'مشروعي');
});

it('round-trips the nested PATCH into the flat full resource and the checklist (#11/#12/#13)', function () use ($term): void {
    $this->actingAs($this->owner, 'api');
    $uuid = $this->postJson('/api/v1/entrepreneur/opportunities', ['name' => 'فرصة الاختبار'])->json('data.uuid');

    $sector = $term('sectors');
    $region = $term('regions');
    $type = $term('investment_types');
    $sdg = $term('sdgs');
    $group = $term('beneficiary_groups');
    $band = $term('revenue_bands');
    $range = $term('investment_ranges');

    $this->patchJson("/api/v1/entrepreneur/opportunities/{$uuid}", [
        'basics' => ['short_description' => 'وصف مختصر', 'sector_id' => $sector->id, 'stage' => 'seed', 'region_ids' => [$region->id], 'investment_type_ids' => [$type->id]],
        'impact' => ['problem_statement' => 'المشكلة', 'solution' => 'الحل', 'expected_change' => 'التغيير', 'beneficiaries_desc' => 'المستفيدون', 'beneficiaries_target' => 500, 'beneficiary_group_ids' => [$group->id], 'sdg_ids' => [$sdg->id]],
        'financials' => ['revenue_state' => 'early', 'funding_amount' => 750000, 'last_year_revenue_band_id' => $band->id, 'funding_range_id' => $range->id, 'use_of_funds' => [['item' => 'تشغيل', 'percent' => 60], ['item' => 'تقنية', 'percent' => 40]]],
        'team' => ['founders_count' => 2, 'team_size' => 4, 'founders_commitment' => 'part_time'],
        'market' => ['product_stage' => 'prototype', 'target_customers' => 'الأسر', 'competitive_advantage' => 'شراكات محلية'],
        'motivation' => ['why_now' => 'جاهزية السوق'],
        'disclosures' => ['legal_disputes' => false, 'data_accuracy_declared' => true, 'teaser_publication_consent' => true],
    ])->assertOk()
        // flat aliases + id lists (web `hydrate()`)
        ->assertJsonPath('data.sector_id', $sector->id)
        ->assertJsonPath('data.region_ids', [$region->id])
        ->assertJsonPath('data.investment_type_ids', [$type->id])
        ->assertJsonPath('data.investment_type_keys', [$type->key])
        ->assertJsonPath('data.beneficiary_group_ids', [$group->id])
        ->assertJsonPath('data.sdg_ids', [$sdg->id])
        ->assertJsonPath('data.last_year_revenue_band_id', $band->id)
        ->assertJsonPath('data.funding_range_id', $range->id)
        ->assertJsonPath('data.problem_statement', 'المشكلة')
        ->assertJsonPath('data.revenue_state', 'early')
        ->assertJsonPath('data.funding_amount', 750000)
        ->assertJsonPath('data.teaser_publication_consent', true)
        ->assertJsonPath('data.disclosures.teaser_publication_consent', true)
        // nested blocks kept
        ->assertJsonPath('data.overview.solution', 'الحل')
        ->assertJsonPath('data.impact.beneficiaries_target', 500)
        ->assertJsonPath('data.sector.key', $sector->key)
        ->assertJsonPath('data.sector.label_ar', $sector->label_ar)
        ->assertJsonStructure(['data' => ['locked_sections', 'reopened_fields', 'completion_requests', 'sla_days_left', 'is_overdue', 'timeline']]);

    expect(Opportunity::query()->where('uuid', $uuid)->firstOrFail()->hasTeaserPublicationConsent())->toBeTrue();

    // indicators through the dedicated routes carry data_source in the full resource
    $this->postJson("/api/v1/entrepreneur/opportunities/{$uuid}/indicators", ['name' => 'عدد المستفيدين', 'unit' => 'شخص', 'baseline' => 0, 'target' => 500, 'data_source' => 'سجلات', 'frequency' => 'quarterly', 'is_primary' => true])
        ->assertCreated();
    $this->getJson("/api/v1/entrepreneur/opportunities/{$uuid}")->assertOk()
        ->assertJsonPath('data.indicators.0.data_source', 'سجلات')
        ->assertJsonPath('data.indicators.0.is_primary', true);

    // checklist: old keys + aliases + items with web step keys
    $checklist = $this->getJson("/api/v1/entrepreneur/opportunities/{$uuid}/checklist")->assertOk()
        ->assertJsonStructure(['data' => ['complete', 'percent', 'missing', 'ready', 'completion_percent', 'items' => [['key', 'label', 'section', 'done', 'kind']]]])
        ->json('data');
    expect($checklist['ready'])->toBeFalse()
        ->and($checklist['completion_percent'])->toBe($checklist['percent'])
        ->and(collect($checklist['items'])->firstWhere('key', 'problem_statement')['section'])->toBe('project')
        ->and(collect($checklist['items'])->firstWhere('key', 'document:pitch_deck')['done'])->toBeFalse();

    // upload the missing pitch deck → ready → submit answers {data:{reference_no}} (#14)
    Document::factory()->forOpportunity(Opportunity::query()->where('uuid', $uuid)->firstOrFail())->create(['kind' => 'pitch_deck']);
    expect($this->getJson("/api/v1/entrepreneur/opportunities/{$uuid}/checklist")->json('data.ready'))->toBeTrue();

    $this->postJson("/api/v1/entrepreneur/opportunities/{$uuid}/submit")->assertOk()
        ->assertJsonPath('data.status', 'new')
        ->assertJsonStructure(['data' => ['reference_no', 'status', 'status_label']]);

    // once submitted every section is locked and the PATCH answers 403 (ENT-05)
    $timeline = $this->getJson("/api/v1/entrepreneur/opportunities/{$uuid}")->assertOk()
        ->assertJsonPath('data.locked_sections', array_keys(Opportunity::WEB_SECTIONS))
        ->json('data.timeline');
    $submitted = collect($timeline)->firstWhere('to_status', 'new');
    expect($submitted)->not->toBeNull()
        ->and($submitted['to'])->toBe('new')
        ->and($submitted['actor']['name'])->toBe($this->owner->name)
        ->and($submitted['actor_name'])->toBe($this->owner->name)
        ->and($submitted['created_at'])->toBe($submitted['at']);
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$uuid}", ['basics' => ['name' => 'تغيير']])->assertForbidden();
});

it('enforces the ENT-05 field-level lock while needs_completion', function (): void {
    $opportunity = Opportunity::factory()->status(OpportunityStatus::New)->create(['owner_user_id' => $this->owner->id]);
    $reviewer = $this->staff('reviewer');

    $this->actingAs($reviewer, 'api');
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/completion-requests", [
        'items' => [['field' => 'problem_statement', 'note' => 'وضّح المشكلة'], ['document_kind' => 'team_cv', 'note' => 'أرفق السير الذاتية']],
    ])->assertCreated()->assertJsonPath('data.status', 'needs_completion');

    $this->actingAs($this->owner, 'api');
    $full = $this->getJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}")->assertOk()
        ->assertJsonPath('data.status', 'needs_completion')
        ->assertJsonPath('data.completion_requests.0.items.0.field', 'problem_statement')
        ->assertJsonPath('data.completion_requests.0.requested_by.name', __('messages.council'))
        ->json('data');
    expect($full['reopened_fields'])->toContain('problem_statement')->toContain('document:team_cv')
        ->and($full['locked_sections'])->toContain('basics')->toContain('financials')->toContain('disclosures')
        ->and($full['locked_sections'])->not->toContain('project')->not->toContain('documents');

    // the reopened field is accepted …
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}", ['impact' => ['problem_statement' => 'مشكلة موضّحة']])
        ->assertOk()->assertJsonPath('data.problem_statement', 'مشكلة موضّحة');

    // … re-sending unchanged values is harmless …
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}", ['impact' => ['problem_statement' => 'مشكلة موضّحة', 'solution' => $opportunity->solution]])->assertOk();

    // … but editing a locked field answers 422 naming it
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}", ['basics' => ['name' => 'اسم جديد'], 'impact' => ['solution' => 'حل مختلف']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['locked_fields', 'basics.name', 'impact.solution']);

    // indicators are locked unless reopened
    $this->postJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}/indicators", ['name' => 'مؤشر', 'is_primary' => true])
        ->assertStatus(422)->assertJsonValidationErrors(['locked_fields']);

    // document kinds: only the requested one
    Illuminate\Support\Facades\Storage::fake('local');
    $pdf = Illuminate\Http\UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');
    $this->post("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}/documents", ['kind' => 'pitch_deck', 'file' => $pdf], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['kind', 'locked_fields']);
    $this->post("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}/documents", ['kind' => 'team_cv', 'file' => $pdf], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.kind', 'team_cv');

    // dashboard "required now" lists both items with the client action/kind + completion_percent
    $dashboard = $this->getJson('/api/v1/entrepreneur/dashboard')->assertOk()
        ->assertJsonStructure(['data' => ['opportunities' => [['uuid', 'completion_percent', 'locked_sections']], 'required_now', 'documents', 'preview', 'info_requests_open', 'meetings_upcoming', 'access_requests']])
        ->json('data');
    $actions = collect($dashboard['required_now'])->pluck('action')->all();
    expect($actions)->toContain('complete')->toContain('upload')
        ->and($dashboard['preview']['uuid'])->toBe($opportunity->uuid)
        ->and($dashboard['documents'][0]['opportunity_uuid'])->toBe($opportunity->uuid)
        ->and($dashboard['documents'][0])->toHaveKey('created_at');
});

it('serves the card and detail contracts to an approved investor with facet counts (#1/#2/#6)', function () use ($term): void {
    $investor = $this->approvedInvestor();
    $sector = $term('sectors');
    $region = $term('regions');
    $type = $term('investment_types');
    $opportunity = Opportunity::factory()->eligible()->create(['sector_id' => $sector->id, 'owner_user_id' => $this->owner->id]);
    $opportunity->regions()->sync([$region->id]);
    $opportunity->investmentTypes()->sync([$type->id]);
    $opportunity->indicators()->create(['name' => 'مؤشر رئيسي', 'unit' => 'شخص', 'baseline' => 0, 'target' => 100, 'data_source' => 'مسح', 'frequency' => 'annual', 'is_primary' => true, 'is_disclosed' => true]);
    Nomination::query()->create(['opportunity_id' => $opportunity->id, 'investor_user_id' => $investor->id, 'match_score' => 82, 'matched_criteria' => ['sector'], 'rationale' => 'توافق', 'teaser_version' => 1, 'sent_at' => now()]);
    app(\App\Services\SettingsService::class)->set('opportunity.access_policy', 'both');

    $this->actingAs($investor, 'api');

    // OPP-10 — counts reflect the applied filters (each facet without its own selection); the factory amount 1M → r3.
    $list = $this->getJson('/api/v1/opportunities?filter[nominated]=1&filter[range]=r3')->assertOk()->json();
    expect($list['meta']['counts']['sector'][$sector->key] ?? 0)->toBe(1)
        ->and($list['meta']['counts']['region'][$region->key] ?? 0)->toBe(1)
        ->and($list['meta']['counts']['type'][$type->key] ?? 0)->toBe(1)
        ->and($list['meta']['counts']['stage']['seed'] ?? 0)->toBe(1)
        ->and($list['meta']['counts']['range'])->toBe(['r3' => 1]);
    $other = $this->getJson('/api/v1/opportunities?filter[nominated]=1&filter[range]='.$term('investment_ranges')->key)->assertOk()->json('meta.counts');
    expect($other['sector'])->toBe([])->and($other['range'])->toBe(['r3' => 1]);

    $cards = $this->getJson('/api/v1/opportunities?filter[nominated]=1')->assertOk()->json('data');
    expect($cards)->toHaveCount(1);
    $card = $cards[0];
    expect($card['teaser_summary'])->toBe('ملخص تعريفي للفرصة')
        ->and($card['teaser_highlights'])->toBe(['نقطة 1', 'نقطة 2'])
        ->and($card['funding_display'])->toHaveKeys(['mode', 'band', 'amount'])
        ->and($card['funding_display_mode'])->toBe($card['funding_display']['mode'])
        ->and($card['investment_type_keys'])->toBe([$type->key])
        ->and($card['investment_types'][0])->toHaveKeys(['id', 'key', 'label', 'label_ar', 'label_en'])
        ->and($card['sector']['label_en'])->toBe($sector->label_en)
        ->and($card['is_nominated'])->toBeTrue();

    $this->getJson("/api/v1/opportunities/{$opportunity->uuid}")->assertOk()
        ->assertJsonPath('data.problem_statement', 'المشكلة الاجتماعية')
        ->assertJsonPath('data.overview.problem_statement', 'المشكلة الاجتماعية')
        ->assertJsonPath('data.primary_indicator.data_source', 'مسح')
        ->assertJsonPath('data.disclosed_indicators', [])
        ->assertJsonPath('data.documents.locked', true)
        ->assertJsonPath('data.risks_full', null)
        ->assertJsonPath('data.my_interest', null)
        ->assertJsonPath('data.my_nomination.match_score', 82)
        ->assertJsonStructure(['data' => ['risks_summary', 'teaser_summary', 'teaser_highlights', 'beneficiary_groups', 'sdgs', 'revenue_state', 'beneficiaries_desc']]);
});

it('previews both access levels for the owner (#15)', function (): void {
    $opportunity = Opportunity::factory()->create(['owner_user_id' => $this->owner->id]);
    $this->actingAs($this->owner, 'api');

    $this->getJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}/preview")->assertOk()
        ->assertJsonStructure(['data' => ['card' => ['teaser_summary', 'funding_display'], 'detail_before_grant' => ['problem_statement', 'documents'], 'detail_after_grant' => ['risks_full']]])
        ->assertJsonPath('data.detail_before_grant.documents.locked', true)
        ->assertJsonPath('data.detail_after_grant.documents.locked', false)
        ->assertJsonPath('data.detail_after_grant.risks_full.operational_risks', 'سري: المخاطر التشغيلية التفصيلية');
});
