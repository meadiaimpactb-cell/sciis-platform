<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsOpportunity;
use App\Models\TaxonomyTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Opportunity
 *
 * SPEC §5 column "teaser/card": public identity + teaser summary (2 lines) + beneficiaries target & groups
 * + funding band (or exact per settings). Nothing confidential, no owner contact (MSG-01).
 *
 * Contract (web `OpportunityCard` / mobile card): `teaser_summary`, `teaser_highlights`,
 * `funding_display: {mode, band, amount}`, `investment_type_keys: string[]`, terms with `label_ar`/`label_en`.
 */
class OpportunityCardResource extends JsonResource
{
    use FormatsOpportunity;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $o->loadMissing(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'organization', 'teaser', 'fundingRange']);
        $viewer = $request->user();

        // OPP-11 — English name / description when the locale is `en` (Arabic fallback); the teaser localizes itself.
        return ['name' => $o->displayName()] + $this->publicIdentity($o) + $this->teaserBlock($o) + [
            'short_description' => $o->displayShortDescription(),
            'beneficiaries_target' => $o->beneficiaries_target,
            'beneficiary_groups' => $o->beneficiaryGroups->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
            'is_saved' => $this->when($viewer !== null && $viewer->isInvestor(), fn () => $o->savedBy()->where('user_id', $viewer->id)->exists()),
            'is_nominated' => $this->when($viewer !== null && $viewer->isInvestor(), fn () => $o->nominations()->where('investor_user_id', $viewer->id)->exists()),
            'published_at' => $o->teaser?->approved_by_council_at?->toIso8601String(),
        ] + $this->fundingBlock($o, false);
    }
}
