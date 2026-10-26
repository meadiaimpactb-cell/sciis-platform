<?php

declare(strict_types=1);

use App\Enums\OpportunityStatus;
use App\Models\Document;
use App\Models\EvaluationAssignment;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;
use App\Services\EvaluationService;

/**
 * ENT-01/02/05 — the accelerator-grade sections: team & founders (س), market & product (public subset), motivation (د).
 * Save + read back (nested + flat), checklist items + web steps, ENT-05 field-level lock, and no founders leak before grant.
 */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->owner = $this->entrepreneur();
});

/** @return array<string, mixed> */
function teamMarketPayload(): array
{
    return [
        'team' => [
            'founders_count' => 2,
            'team_size' => 7,
            'founders_commitment' => 'transitioning',
            'founders_commitment_note' => 'التفرّغ الكامل خلال 3 أشهر',
            'prior_startup_experience' => true,
            'prior_startup_experience_desc' => 'أسس شركة تقنية تعليمية سابقًا',
            'industry_experience' => true,
            'industry_experience_desc' => 'عشر سنوات في الخدمات الاجتماعية',
            'founders_known_years' => '5_plus',
            'has_advisory_board' => true,
            'advisory_board_desc' => 'ثلاثة مستشارين من القطاع غير الربحي',
            'dev_team_structure' => 'hybrid',
            'founders_agreement_signed' => true,
            'founders' => [
                ['name' => 'ريم العتيبي', 'role' => 'الرئيسة التنفيذية', 'linkedin_url' => 'https://www.linkedin.com/in/reem-founder', 'is_full_time' => true],
                ['name' => '  فهد القحطاني ', 'role' => 'المدير التقني', 'linkedin_url' => null, 'is_full_time' => false],
            ],
        ],
        'market' => [
            'target_customers' => 'الأسر محدودة الدخل في المدن المتوسطة',
            'market_size_desc' => 'TAM 2 مليار، SAM 400 مليون، SOM 40 مليون ريال',
            'competitors' => 'مبادرات حكومية ومنصات خاصة',
            'competitive_advantage' => 'شبكة شركاء ميدانيين',
            'go_to_market' => 'الشراكة مع الجمعيات الأهلية',
            'product_stage' => 'launched',
            'technology_desc' => 'تطبيق جوال وخدمة رسائل',
            'traction_desc' => '1,200 مستخدم نشط وتجربتان مع أمانات',
            'scalability_desc' => 'نموذج قابل للتكرار في 5 مناطق',
        ],
        'motivation' => [
            'why_now' => 'نضج الطلب بعد التجارب الأولى',
            'support_needed' => ['market_access', 'mentoring', 'mentoring'],
            'program_expectations' => 'الوصول لمستثمرين أثر',
            'how_heard_id' => TaxonomyTerm::query()->group('how_heard')->where('key', 'event')->value('id'),
        ],
    ];
}

it('saves the team, market and motivation sections and reads them back nested and flat', function (): void {
    $this->actingAs($this->owner, 'api');
    $uuid = $this->postJson('/api/v1/entrepreneur/opportunities', ['name' => 'فرصة الفريق'])->assertCreated()->json('data.uuid');
    $payload = teamMarketPayload();
    $payload['motivation']['support_needed'] = ['market_access', 'mentoring']; // `distinct` is validated (see below)

    $data = $this->patchJson("/api/v1/entrepreneur/opportunities/{$uuid}", $payload)->assertOk()->json('data');

    // nested blocks
    expect($data['team']['founders_count'])->toBe(2)
        ->and($data['team']['team_size'])->toBe(7)
        ->and($data['team']['founders_commitment'])->toBe('transitioning')
        ->and($data['team']['founders_commitment_label'])->toBe(__('enums.founders_commitment.transitioning'))
        ->and($data['team']['founders_known_years'])->toBe('5_plus')
        ->and($data['team']['has_advisory_board'])->toBeTrue()
        ->and($data['team']['founders'])->toHaveCount(2)
        ->and($data['team']['founders'][1])->toBe(['name' => 'فهد القحطاني', 'role' => 'المدير التقني', 'linkedin_url' => null, 'is_full_time' => false])
        ->and($data['market']['product_stage'])->toBe('launched')
        ->and($data['market']['product_stage_label'])->toBe(__('enums.product_stage.launched'))
        ->and($data['market']['market_size_desc'])->toContain('TAM')
        ->and($data['motivation']['support_needed'])->toBe(['mentoring', 'market_access']) // canonical order
        ->and($data['motivation']['how_heard']['key'])->toBe('event');

    // flat aliases (form hydration)
    expect($data['founders_count'])->toBe(2)
        ->and($data['founders'][0]['linkedin_url'])->toBe('https://www.linkedin.com/in/reem-founder')
        ->and($data['target_customers'])->toBe('الأسر محدودة الدخل في المدن المتوسطة')
        ->and($data['why_now'])->toBe('نضج الطلب بعد التجارب الأولى')
        ->and($data['how_heard_id'])->toBe($payload['motivation']['how_heard_id']);

    // read back through GET
    $this->getJson("/api/v1/entrepreneur/opportunities/{$uuid}")->assertOk()
        ->assertJsonPath('data.team.dev_team_structure', 'hybrid')
        ->assertJsonPath('data.market.traction_desc', '1,200 مستخدم نشط وتجربتان مع أمانات')
        ->assertJsonPath('data.motivation.program_expectations', 'الوصول لمستثمرين أثر');
});

it('validates the new sections', function (): void {
    $this->actingAs($this->owner, 'api');
    $uuid = $this->postJson('/api/v1/entrepreneur/opportunities', [])->json('data.uuid');
    $otherGroupTerm = TaxonomyTerm::query()->group('sectors')->value('id');
    $sevenFounders = array_fill(0, 7, ['name' => 'مؤسس']);

    $this->patchJson("/api/v1/entrepreneur/opportunities/{$uuid}", [
        'team' => ['founders_count' => 0, 'founders_commitment' => 'sometimes', 'founders_known_years' => '<1', 'dev_team_structure' => 'x', 'founders' => $sevenFounders],
        'market' => ['product_stage' => 'seed'],
        'motivation' => ['support_needed' => ['money', 'mentoring', 'mentoring'], 'how_heard_id' => $otherGroupTerm],
    ])->assertStatus(422)->assertJsonValidationErrors([
        'team.founders_count', 'team.founders_commitment', 'team.founders_known_years', 'team.dev_team_structure', 'team.founders',
        'market.product_stage', 'motivation.support_needed.0', 'motivation.support_needed.1', 'motivation.how_heard_id',
    ]);

    // founder rows need a name, LinkedIn must be a URL, and the team includes the founders
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$uuid}", [
        'team' => ['founders_count' => 3, 'team_size' => 2, 'founders' => [['role' => 'CTO', 'linkedin_url' => 'not-a-url']]],
    ])->assertStatus(422)->assertJsonValidationErrors(['team.team_size', 'team.founders.0.name', 'team.founders.0.linkedin_url']);
});

it('requires the readiness items in the checklist and links them to the right web steps', function (): void {
    $this->actingAs($this->owner, 'api');
    $uuid = $this->postJson('/api/v1/entrepreneur/opportunities', ['name' => 'فرصة الجاهزية'])->json('data.uuid');

    $required = ['founders_count' => 'team', 'team_size' => 'team', 'founders_commitment' => 'team', 'product_stage' => 'market', 'target_customers' => 'market', 'competitive_advantage' => 'market', 'why_now' => 'motivation'];

    $items = collect($this->getJson("/api/v1/entrepreneur/opportunities/{$uuid}/checklist")->assertOk()->json('data.items'))->keyBy('key');
    foreach ($required as $key => $section) {
        expect($items[$key]['section'])->toBe($section)
            ->and($items[$key]['done'])->toBeFalse()
            ->and($items[$key]['label'])->toBe(__("validation.attributes.{$key}"));
    }
    // optional answers are not checklist items
    expect($items->has('market_size_desc'))->toBeFalse()->and($items->has('founders'))->toBeFalse()->and($items->has('support_needed'))->toBeFalse();

    $missing = collect($this->getJson("/api/v1/entrepreneur/opportunities/{$uuid}/checklist")->json('data.missing'))->keyBy('key');
    expect($missing['founders_count']['section'])->toBe('team')
        ->and($missing['product_stage']['section'])->toBe('market')
        ->and($missing['why_now']['section'])->toBe('motivation');

    $this->patchJson("/api/v1/entrepreneur/opportunities/{$uuid}", [
        'team' => ['founders_count' => 1, 'team_size' => 1, 'founders_commitment' => 'full_time'],
        'market' => ['product_stage' => 'idea', 'target_customers' => 'طلاب', 'competitive_advantage' => 'تكلفة أقل'],
        'motivation' => ['why_now' => 'فرصة تنظيمية'],
    ])->assertOk();

    $items = collect($this->getJson("/api/v1/entrepreneur/opportunities/{$uuid}/checklist")->json('data.items'))->keyBy('key');
    foreach (array_keys($required) as $key) {
        expect($items[$key]['done'])->toBeTrue();
    }

    // a factory-complete opportunity without the readiness answers cannot be submitted
    $o = Opportunity::factory()->create(['owner_user_id' => $this->owner->id, 'founders_count' => null, 'why_now' => null]);
    Document::factory()->forOpportunity($o)->create(['kind' => 'pitch_deck']);
    $o->indicators()->create(['name' => 'مؤشر', 'is_primary' => true, 'target' => 10]);
    $o->regions()->sync([TaxonomyTerm::query()->group('regions')->value('id')]);
    $o->investmentTypes()->sync([TaxonomyTerm::query()->group('investment_types')->value('id')]);
    $o->forceFill(['sector_id' => TaxonomyTerm::query()->group('sectors')->value('id')])->save();

    $response = $this->postJson("/api/v1/entrepreneur/opportunities/{$o->uuid}/submit")->assertStatus(422)->assertJsonPath('code', 'checklist_incomplete');
    expect(json_encode($response->json(), JSON_UNESCAPED_UNICODE))
        ->toContain(__('validation.attributes.founders_count'))
        ->toContain(__('validation.attributes.why_now'));

    $o->forceFill(['founders_count' => 2, 'why_now' => 'الآن'])->save();
    $this->postJson("/api/v1/entrepreneur/opportunities/{$o->uuid}/submit")->assertOk()->assertJsonPath('data.status', 'new');
});

it('applies the ENT-05 field-level lock to the new sections', function (): void {
    $opportunity = Opportunity::factory()->status(OpportunityStatus::New)->create(['owner_user_id' => $this->owner->id]);
    $reviewer = $this->staff('reviewer');

    // the reviewer reopens the whole team step + a single motivation field
    $this->actingAs($reviewer, 'api');
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/completion-requests", [
        'items' => [['field' => 'team', 'note' => 'أضف بيانات المؤسسين'], ['field' => 'why_now', 'note' => 'وضّح التوقيت']],
    ])->assertCreated()->assertJsonPath('data.status', 'needs_completion');

    $this->actingAs($this->owner, 'api');
    $full = $this->getJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}")->assertOk()->json('data');
    expect($full['reopened_fields'])->toContain('founders')->toContain('team_size')->toContain('why_now')
        ->and($full['reopened_fields'])->not->toContain('target_customers')->not->toContain('support_needed')
        ->and($full['locked_sections'])->not->toContain('team')->not->toContain('motivation')
        ->and($full['locked_sections'])->toContain('market')->toContain('basics');

    // reopened: the whole team section + why_now
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}", [
        'team' => ['team_size' => 9, 'founders' => [['name' => 'مؤسس جديد', 'role' => 'CEO', 'linkedin_url' => 'https://www.linkedin.com/in/new-founder', 'is_full_time' => true]]],
        'motivation' => ['why_now' => 'توقيت موضّح'],
    ])->assertOk()
        ->assertJsonPath('data.team.team_size', 9)
        ->assertJsonPath('data.team.founders.0.name', 'مؤسس جديد')
        ->assertJsonPath('data.why_now', 'توقيت موضّح');

    // re-sending unchanged values of locked fields is harmless (incl. a JSON list in another selection order)
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}", [
        'market' => ['target_customers' => $opportunity->target_customers, 'product_stage' => $opportunity->product_stage],
        'motivation' => ['support_needed' => ['investor_intros', 'mentoring'], 'how_heard_id' => null, 'program_expectations' => $opportunity->program_expectations],
    ])->assertOk();

    // … but editing a field that was not reopened answers 422 naming it
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}", [
        'market' => ['competitive_advantage' => 'ميزة مختلفة'],
        'motivation' => ['support_needed' => ['legal']],
    ])->assertStatus(422)->assertJsonValidationErrors(['locked_fields', 'market.competitive_advantage', 'motivation.support_needed']);

    expect($opportunity->fresh()->competitive_advantage)->toBe('الميزة التنافسية');
});

it('keeps a locked founders list locked but tolerates re-sending it unchanged', function (): void {
    $opportunity = Opportunity::factory()->status(OpportunityStatus::New)->create(['owner_user_id' => $this->owner->id]);
    $this->actingAs($this->staff('reviewer'), 'api');
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/completion-requests", ['items' => [['field' => 'why_now', 'note' => 'وضّح']]])->assertCreated();

    $this->actingAs($this->owner, 'api');
    $stored = $opportunity->fresh()->founders;
    // same founders, keys reordered, is_full_time as 0/1 → unchanged
    $resent = array_map(fn (array $f): array => array_reverse($f, true), $stored);
    $resent = array_map(fn (array $f): array => ['is_full_time' => $f['is_full_time'] ? 1 : 0] + $f, $resent);
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}", [
        'team' => ['founders' => $resent, 'founders_count' => 2],
        'motivation' => ['why_now' => 'محدّث'],
    ])->assertOk()->assertJsonPath('data.why_now', 'محدّث');

    // renaming a founder is an edit → 422 on team.founders
    $resent[0]['name'] = 'اسم آخر';
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}", ['team' => ['founders' => $resent]])
        ->assertStatus(422)->assertJsonValidationErrors(['locked_fields', 'team.founders']);
    expect($opportunity->fresh()->founders[0]['name'])->toBe('سري: المؤسس الأول');
});

it('never leaks founders before the owner grant, but shows them to granted investors and assigned evaluators', function (): void {
    $opportunity = Opportunity::factory()->status(OpportunityStatus::UnderReview)->create(['owner_user_id' => $this->owner->id]);

    // owner preview: before-grant view has the public market subset only
    $this->actingAs($this->owner, 'api');
    $preview = $this->getJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}/preview")->assertOk()->json('data');
    $before = json_encode([$preview['card'], $preview['detail_before_grant']], JSON_UNESCAPED_UNICODE);
    expect($before)->not->toContain('سري: المؤسس الأول')->not->toContain('linkedin')->not->toContain('"founders"')
        ->not->toContain('خاص: حجم السوق')->not->toContain('داخلي: لماذا الآن')
        ->and($preview['detail_before_grant']['team'])->toBeNull()
        ->and($preview['detail_before_grant']['competitive_advantage'])->toBe('الميزة التنافسية')
        ->and($preview['detail_after_grant']['team']['founders'][0]['name'])->toBe('سري: المؤسس الأول')
        ->and(json_encode($preview['detail_after_grant'], JSON_UNESCAPED_UNICODE))->not->toContain('داخلي: لماذا الآن');

    // assigned evaluator (no COI) reads the full team / market / motivation to score readiness
    $reviewer = $this->staff('reviewer');
    $evaluatorA = $this->staff('evaluator');
    $evaluatorB = $this->staff('evaluator');
    app(EvaluationService::class)->assign($opportunity, [$evaluatorA->id, $evaluatorB->id], $reviewer);
    $assignment = EvaluationAssignment::query()->where('evaluator_user_id', $evaluatorA->id)->firstOrFail();

    $this->actingAs($evaluatorA, 'api');
    $this->postJson("/api/v1/admin/evaluation-assignments/{$assignment->id}/coi", ['has_conflict' => false])->assertOk();
    $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}")->assertOk()
        ->assertJsonPath('data.team.founders.1.linkedin_url', 'https://www.linkedin.com/in/secret-founder-two')
        ->assertJsonPath('data.market.scalability_desc', 'خاص: قابلية التوسع')
        ->assertJsonPath('data.motivation.why_now', 'داخلي: لماذا الآن');
});
