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

        return $this->publicIdentity($o) + [
            'teaser' => $o->teaser ? [
                'summary' => $o->teaser->summary(),
                'highlights' => $o->teaser->highlights ?? [],
                'version' => $o->teaser->version,
            ] : null,
            'short_description' => $o->short_description,
            'beneficiaries_target' => $o->beneficiaries_target,
            'beneficiary_groups' => $o->beneficiaryGroups->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
            'is_saved' => $this->when($viewer !== null && $viewer->isInvestor(), fn () => $o->savedBy()->where('user_id', $viewer->id)->exists()),
            'is_nominated' => $this->when($viewer !== null && $viewer->isInvestor(), fn () => $o->nominations()->where('investor_user_id', $viewer->id)->exists()),
            'published_at' => $o->teaser?->approved_by_council_at?->toIso8601String(),
        ] + $this->fundingBlock($o, false);
    }
}
