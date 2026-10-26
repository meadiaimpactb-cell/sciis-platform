<?php

declare(strict_types=1);

use App\Models\Consent;
use App\Models\FileAccessGrant;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\Page;
use App\Services\SettingsService;
use App\Support\NdaDraft;

/** MSG-02 — the investor reads the full NDA (CMS text, server version) before accepting; the consent keeps a snapshot. */
beforeEach(function (): void {
    $this->owner = $this->entrepreneur();
    $this->opportunity = Opportunity::factory()->eligible()->create(['owner_user_id' => $this->owner->id]);
    $this->investor = $this->approvedInvestor();
    Nomination::query()->create(['opportunity_id' => $this->opportunity->id, 'investor_user_id' => $this->investor->id, 'match_score' => 2, 'matched_criteria' => ['sector'], 'sent_at' => now()]);
    $this->approve = fn () => FileAccessGrant::query()->create(['opportunity_id' => $this->opportunity->id, 'investor_user_id' => $this->investor->id, 'granted_by_owner_at' => now()]);
});

it('serves the full bilingual NDA text with the server version from the CMS page', function (): void {
    $this->actingAs($this->investor, 'api');

    $ar = $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda")->assertOk()->json('data');
    expect($ar['version'])->toBe('1.0')
        ->and($ar['locale'])->toBe('ar')
        ->and($ar['body'])->toBe(trim(NdaDraft::BODY_AR))
        ->and($ar['text_hash'])->toBe(hash('sha256', $ar['body'].'|1.0'))
        ->and($ar['pending_legal'])->toBeTrue()
        ->and($ar['can_accept'])->toBeFalse()
        ->and($ar['accepted_at'])->toBeNull();

    $en = $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda?locale=en")->assertOk()->json('data');
    expect($en['locale'])->toBe('en')->and($en['body'])->toBe(trim(NdaDraft::BODY_EN))->and($en['title'])->toBe(NdaDraft::TITLE_EN);

    // English body removed by staff → falls back to the Arabic text (and says so)
    Page::query()->where('slug', 'nda')->update(['body_en' => null]);
    expect($this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda?locale=en")->json('data.locale'))->toBe('ar');

    ($this->approve)();
    expect($this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda")->json('data.can_accept'))->toBeTrue();
});

it('rejects a stale or hard-coded version and stores version + hash + full text snapshot on accept', function (): void {
    ($this->approve)();
    $this->actingAs($this->investor, 'api');

    $this->postJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda-accept", ['version' => '0.3-draft'])
        ->assertStatus(422)->assertJsonPath('code', 'nda_version_mismatch')->assertJsonValidationErrors(['version']);
    expect(Consent::query()->where('type', 'nda')->count())->toBe(0);

    $nda = $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda?locale=en")->json('data');
    $this->withHeaders(['User-Agent' => 'PestBrowser/1.0'])
        ->postJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda-accept", ['version' => $nda['version'], 'locale' => 'en'])
        ->assertOk()->assertJsonPath('data.granted', true)->assertJsonPath('data.nda_version', '1.0');

    $consent = Consent::query()->where('user_id', $this->investor->id)->where('type', 'nda')->sole();
    expect($consent->version)->toBe('1.0')
        ->and($consent->text_snapshot)->toBe($nda['body'])
        ->and($consent->locale)->toBe('en')
        ->and($consent->text_hash)->toBe($nda['text_hash'])
        ->and($consent->text_hash)->toBe(hash('sha256', $consent->text_snapshot.'|'.$consent->version))
        ->and($consent->ip)->not->toBeNull()
        ->and($consent->user_agent)->toBe('PestBrowser/1.0')
        ->and($consent->accepted_at)->not->toBeNull()
        ->and((int) $consent->subject_id)->toBe($this->opportunity->id);

    $after = $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda")->json('data');
    expect($after['accepted_version'])->toBe('1.0')->and($after['accepted_at'])->not->toBeNull()->and($after['can_accept'])->toBeFalse();
});

it('follows the edited CMS text and the bumped version', function (): void {
    ($this->approve)();
    Page::query()->where('slug', 'nda')->update(['body_ar' => "# نص معدّل\n\nبند جديد للسرية."]);
    app(SettingsService::class)->set('legal.nda_version', '1.1');
    $this->actingAs($this->investor, 'api');

    $nda = $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda")->assertOk()->json('data');
    expect($nda['version'])->toBe('1.1')->and($nda['body'])->toContain('بند جديد للسرية')->and($nda['pending_legal'])->toBeFalse();

    $this->postJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda-accept", ['version' => '1.0'])->assertStatus(422)->assertJsonPath('code', 'nda_version_mismatch');
    $this->postJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda-accept", ['version' => '1.1'])->assertOk();

    expect(Consent::query()->where('type', 'nda')->sole()->text_snapshot)->toContain('بند جديد للسرية');
});

it('keeps the NDA endpoints for approved members only', function (): void {
    $this->actingAs($this->registeredInvestor(), 'api');
    $this->getJson("/api/v1/opportunities/{$this->opportunity->uuid}/nda")->assertForbidden();
});
