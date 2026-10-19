<?php

declare(strict_types=1);

use App\Models\Opportunity;
use App\Models\TaxonomyTerm;
use App\Services\MatchingService;

/** MAT-02 (count of matched criteria) and MAT-04 (COI exclusion). */
beforeEach(function (): void {
    $this->sector = TaxonomyTerm::query()->group('sectors')->where('key', 'education')->firstOrFail();
    $this->region = TaxonomyTerm::query()->group('regions')->where('key', 'riyadh')->firstOrFail();
    $this->type = TaxonomyTerm::query()->group('investment_types')->where('key', 'equity')->firstOrFail();
    $this->stage = TaxonomyTerm::query()->group('project_stages')->where('key', 'seed')->firstOrFail();
    $this->range = TaxonomyTerm::query()->group('investment_ranges')->where('key', 'r2')->firstOrFail();

    $this->opportunity = Opportunity::factory()->eligible()->create(['name' => 'منصة سُلّم للتدريب', 'sector_id' => $this->sector->id, 'stage' => 'seed', 'funding_range_id' => $this->range->id]);
    $this->opportunity->regions()->sync([$this->region->id]);
    $this->opportunity->investmentTypes()->sync([$this->type->id]);
});

it('scores candidates by the number of matched criteria and ranks them', function (): void {
    $full = $this->approvedInvestor();
    $full->investorProfile->update(['investment_range_id' => $this->range->id]);
    $full->investorProfile->sectors()->sync([$this->sector->id]);
    $full->investorProfile->stages()->sync([$this->stage->id]);
    $full->investorProfile->regions()->sync([$this->region->id]);
    $full->investorProfile->investmentTypes()->sync([$this->type->id]);

    $partial = $this->approvedInvestor();
    $partial->investorProfile->sectors()->sync([$this->sector->id]);
    $partial->investorProfile->regions()->sync([$this->region->id]);

    $none = $this->approvedInvestor();

    $candidates = app(MatchingService::class)->candidates($this->opportunity);

    expect($candidates)->toHaveCount(3)
        ->and($candidates[0]['user']->id)->toBe($full->id)
        ->and($candidates[0]['score'])->toBe(5)
        ->and($candidates[0]['matched'])->toBe(['sector', 'stage', 'region', 'investment_type', 'funding_range'])
        ->and($candidates[1]['user']->id)->toBe($partial->id)
        ->and($candidates[1]['score'])->toBe(2)
        ->and($candidates[2]['user']->id)->toBe($none->id)
        ->and($candidates[2]['score'])->toBe(0);
});

it('excludes investors with a declared conflict of interest', function (): void {
    $conflicted = $this->approvedInvestor();
    $conflicted->investorProfile->update(['conflicts_disclosure' => 'عضو مجلس إدارة في منصة سُلّم للتدريب']);
    $conflicted->investorProfile->sectors()->sync([$this->sector->id]);

    $clean = $this->approvedInvestor();

    $ids = app(MatchingService::class)->candidates($this->opportunity)->pluck('user.id');

    expect($ids)->not->toContain($conflicted->id)->toContain($clean->id);

    $officer = $this->staff('matching_officer');
    expect(fn () => app(MatchingService::class)->nominate($this->opportunity, [$conflicted->id], 'سبب', $officer))
        ->toThrow(\App\Exceptions\DomainException::class);
});

it('ignores non-approved investors', function (): void {
    $registered = $this->registeredInvestor();
    $approved = $this->approvedInvestor();

    $ids = app(MatchingService::class)->candidates($this->opportunity)->pluck('user.id');

    expect($ids)->toContain($approved->id)->not->toContain($registered->id);
});

it('respects the max nominations setting', function (): void {
    app(\App\Services\SettingsService::class)->set('opportunity.max_nominations_per_opportunity', 1);
    $officer = $this->staff('matching_officer');
    $a = $this->approvedInvestor();
    $b = $this->approvedInvestor();

    app(MatchingService::class)->nominate($this->opportunity, [$a->id], 'سبب', $officer);
    expect(fn () => app(MatchingService::class)->nominate($this->opportunity, [$b->id], 'سبب', $officer))->toThrow(\App\Exceptions\DomainException::class);
});
