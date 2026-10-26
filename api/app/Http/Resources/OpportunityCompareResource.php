<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunity;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Opportunity
 *
 * OPP-12 — one comparison column: public identity + teaser + impact (beneficiaries, groups, SDGs, primary indicator)
 * + revenue state + public product stage + funding per `funding_display` (never the exact amount unless the setting
 * says so). Same sensitivity as the card / approved-member detail (SPEC §5).
 */
class OpportunityCompareResource extends JsonResource
{
    use FormatsOpportunity;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $o->loadMissing(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'sdgs', 'organization', 'teaser', 'fundingRange', 'primaryIndicator']);
        $terms = fn ($items): array => $items->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all();
        $primary = $o->primaryIndicator;

        return ['name' => $o->displayName()] + $this->publicIdentity($o) + $this->teaserBlock($o) + [
            'short_description' => $o->displayShortDescription(),
            'beneficiaries_target' => $o->beneficiaries_target,
            'beneficiaries_current' => $o->beneficiaries_current,
            'beneficiary_groups' => $terms($o->beneficiaryGroups),
            'sdgs' => $terms($o->sdgs),
            'primary_indicator' => $primary ? ['name' => $primary->name, 'unit' => $primary->unit, 'baseline' => $primary->baseline !== null ? (float) $primary->baseline : null, 'target' => $primary->target !== null ? (float) $primary->target : null] : null,
            'revenue_state' => $o->revenue_state?->value,
            'revenue_state_label' => $o->revenue_state?->label(),
            'product_stage' => $o->product_stage,
            'product_stage_label' => $this->enumLabel('product_stage', $o->product_stage),
            'published_at' => $o->teaser?->approved_by_council_at?->toIso8601String(),
        ] + $this->fundingBlock($o, false);
    }
}
