<?php

declare(strict_types=1);

use App\Enums\InvestorAccessLevel;
use App\Http\Resources\OpportunityCardResource;
use App\Http\Resources\OpportunityDetailResource;
use App\Http\Resources\OpportunityFullResource;
use App\Models\Document;
use App\Models\Opportunity;
use Illuminate\Http\Request;

/**
 * MSG-01 acceptance — no confidential / sensitive_personal field leaks below its level (SPEC §5).
 */
const CONFIDENTIAL_MARKERS = [
    'سري: تفاصيل التمويل السابق',
    'سري: تفاصيل النزاعات',
    'سري: المخاطر التشغيلية التفصيلية',
    'داخلي: علاقة بالمجلس',
];

function resourceJson(mixed $resource, ?\App\Models\User $viewer = null): string
{
    $request = Request::create('/api/v1/opportunities', 'GET');
    if ($viewer) {
        $request->setUserResolver(fn () => $viewer);
    }

    return json_encode($resource->resolve($request), JSON_UNESCAPED_UNICODE);
}

beforeEach(function (): void {
    $this->owner = $this->entrepreneur();
    $this->owner->forceFill(['email' => 'owner-secret@example.test', 'phone' => '+966500009999'])->save();
    $this->opportunity = Opportunity::factory()->eligible()->create(['owner_user_id' => $this->owner->id, 'funding_amount' => 1234567.89]);
    Document::factory()->forOpportunity($this->opportunity)->create(['title' => 'SECRET-DOC-TITLE']);
    $this->investor = $this->approvedInvestor();
});

it('card resource never exposes confidential or personal data', function (): void {
    $json = resourceJson(new OpportunityCardResource($this->opportunity), $this->investor);

    foreach (CONFIDENTIAL_MARKERS as $marker) {
        expect($json)->not->toContain($marker);
    }
    expect($json)->not->toContain('owner-secret@example.test')
        ->not->toContain('+966500009999')
        ->not->toContain('SECRET-DOC-TITLE')
        ->not->toContain('1234567.89') // funding_display defaults to band
        ->not->toContain('use_of_funds')
        ->not->toContain('operational_risks');
});

it('detail resource at approved_member level hides confidential text, documents and contact', function (): void {
    $json = resourceJson(new OpportunityDetailResource($this->opportunity, InvestorAccessLevel::ApprovedMember), $this->investor);
    $data = json_decode($json, true);

    foreach (CONFIDENTIAL_MARKERS as $marker) {
        expect($json)->not->toContain($marker);
    }
    expect($json)->not->toContain('owner-secret@example.test')
        ->not->toContain('+966500009999')
        ->not->toContain('SECRET-DOC-TITLE')
        ->and($data['documents']['locked'])->toBeTrue()
        ->and($data['documents']['items'])->toBe([])
        ->and($data['funding_amount'])->toBeNull()
        ->and($data['funding_display'])->toBe('band')
        ->and($data)->not->toHaveKey('owner_contact')
        ->and($data['risks'])->not->toHaveKey('operational_risks')
        ->and($data['use_of_funds'][0])->toHaveKeys(['item', 'percent']);
    // public fields ARE present at this level
    expect($data['overview']['problem_statement'])->toBe('المشكلة الاجتماعية');
});

it('detail resource at granted level reveals confidential text and documents but still no owner contact', function (): void {
    $json = resourceJson(new OpportunityDetailResource($this->opportunity, InvestorAccessLevel::Granted), $this->investor);
    $data = json_decode($json, true);

    expect($json)->toContain('سري: المخاطر التشغيلية التفصيلية')
        ->toContain('SECRET-DOC-TITLE')
        ->not->toContain('owner-secret@example.test')
        ->not->toContain('+966500009999')
        ->and($data['documents']['locked'])->toBeFalse()
        ->and($data['funding_amount'])->toBe(1234567.89)
        ->and($data)->not->toHaveKey('owner_contact');
});

it('detail resource at connected level exposes owner contact (sensitive_personal)', function (): void {
    $data = json_decode(resourceJson(new OpportunityDetailResource($this->opportunity, InvestorAccessLevel::Connected), $this->investor), true);

    expect($data['owner_contact']['email'])->toBe('owner-secret@example.test')
        ->and($data['owner_contact']['phone'])->toBe('+966500009999');
});

it('internal fields never reach the owner through the full resource, but do reach staff', function (): void {
    $this->opportunity->internalNotes()->create(['author_id' => $this->staff('reviewer')->id, 'body' => 'INTERNAL-NOTE-BODY']);

    $ownerJson = resourceJson(new OpportunityFullResource($this->opportunity), $this->owner);
    expect($ownerJson)->not->toContain('INTERNAL-NOTE-BODY')->not->toContain('"internal_notes"');

    $staffJson = resourceJson(new OpportunityFullResource($this->opportunity), $this->staff('super_admin'));
    expect($staffJson)->toContain('INTERNAL-NOTE-BODY');
});

it('GET /opportunities/{uuid} returns detail at approved_member level without leaks', function (): void {
    config(['sciis.features.english' => false]);
    $this->actingAs($this->investor, 'api');
    // Policy: browse_all makes it visible without a nomination.
    app(\App\Services\SettingsService::class)->set('opportunity.access_policy', 'browse_all');

    $response = $this->getJson('/api/v1/opportunities/'.$this->opportunity->uuid)->assertOk();
    $json = $response->getContent();

    foreach (CONFIDENTIAL_MARKERS as $marker) {
        expect($json)->not->toContain($marker);
    }
    expect($json)->not->toContain('owner-secret@example.test')
        ->and($response->json('data.access_level'))->toBe('approved_member')
        ->and($response->json('data.documents.locked'))->toBeTrue();
});
