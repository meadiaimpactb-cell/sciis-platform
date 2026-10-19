<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Enums\FundingDisplay;
use App\Models\ImpactIndicator;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;

/** Shared formatting for the three opportunity resources (SPEC §5 sensitivity matrix). */
trait FormatsOpportunity
{
    /**
     * Public-sensitivity identity block: name, organization name, sector, stage, regions,
     * investment types, founded year — allowed on card, detail and full views.
     *
     * @return array<string, mixed>
     */
    protected function publicIdentity(Opportunity $o): array
    {
        return [
            'uuid' => $o->uuid,
            'reference_no' => $o->reference_no,
            'name' => $o->name,
            'organization_name' => $o->organization?->name,
            'founded_year' => $o->organization?->foundedYear(),
            'sector' => $this->term($o->sector),
            'stage' => $o->stage?->value,
            'stage_label' => $o->stage?->label(),
            'regions' => $o->regions->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
            'investment_types' => $o->investmentTypes->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'status_color' => $o->status->color(),
            'website' => $o->website,
        ];
    }

    /** @return array<string, mixed>|null */
    protected function term(?TaxonomyTerm $term): ?array
    {
        return $term === null ? null : ['id' => $term->id, 'key' => $term->key, 'label' => $term->label()];
    }

    /**
     * Funding amount per `settings.funding_display` (SPEC §5): band | exact | hidden.
     * The exact value is confidential → only when display=exact, or when the viewer is granted/owner/staff.
     *
     * @return array<string, mixed>
     */
    protected function fundingBlock(Opportunity $o, bool $exactAllowed): array
    {
        $mode = $o->fundingDisplay();
        $band = $this->term($o->fundingRange);

        if ($mode === FundingDisplay::Hidden && ! $exactAllowed) {
            return ['funding_display' => 'hidden', 'funding_band' => null, 'funding_amount' => null];
        }

        if ($mode === FundingDisplay::Exact || $exactAllowed) {
            return [
                'funding_display' => 'exact',
                'funding_band' => $band,
                'funding_amount' => $o->funding_amount !== null ? (float) $o->funding_amount : null,
                'currency' => 'SAR',
            ];
        }

        return ['funding_display' => 'band', 'funding_band' => $band, 'funding_amount' => null];
    }

    /** @return array<string, mixed>|null */
    protected function indicator(?ImpactIndicator $i): ?array
    {
        if ($i === null) {
            return null;
        }

        return [
            'id' => $i->id,
            'name' => $i->name,
            'definition' => $i->definition,
            'unit' => $i->unit,
            'baseline' => $i->baseline !== null ? (float) $i->baseline : null,
            'target' => $i->target !== null ? (float) $i->target : null,
            'frequency' => $i->frequency?->value,
            'frequency_label' => $i->frequency?->label(),
            'is_primary' => $i->is_primary,
            'is_disclosed' => $i->is_disclosed,
            'sdg' => $this->term($i->sdg),
        ];
    }

    /** Summary % only (confidential → "summary %" before grant). @return array<int, array{item: string, percent: float}> */
    protected function useOfFundsSummary(Opportunity $o): array
    {
        return collect((array) $o->use_of_funds)
            ->map(fn (array $row): array => ['item' => (string) ($row['item'] ?? ''), 'percent' => (float) ($row['percent'] ?? 0)])
            ->values()
            ->all();
    }

    /** Risk & disclosure summary (yes/no flags only, no descriptions) — before grant. @return array<string, mixed> */
    protected function risksSummary(Opportunity $o): array
    {
        return [
            'prior_funding' => $o->prior_funding,
            'legal_disputes' => $o->legal_disputes,
            'license_required' => $o->license_required,
            'license_status' => $o->license_status,
            'has_operational_risks' => $o->operational_risks !== null && $o->operational_risks !== '',
        ];
    }

    /** Full risk & disclosure text — after grant (confidential). @return array<string, mixed> */
    protected function risksFull(Opportunity $o): array
    {
        return $this->risksSummary($o) + [
            'prior_funding_desc' => $o->prior_funding_desc,
            'legal_disputes_desc' => $o->legal_disputes_desc,
            'operational_risks' => $o->operational_risks,
        ];
    }
}
