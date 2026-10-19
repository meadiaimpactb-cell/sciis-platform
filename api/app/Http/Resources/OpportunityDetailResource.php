<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\InvestorAccessLevel;
use App\Http\Resources\Concerns\FormatsOpportunity;
use App\Models\ImpactIndicator;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Opportunity
 *
 * SPEC §5 "details" view at the caller's access level:
 *  - approved_member (before grant): public fields + problem/solution/expected change + primary & disclosed
 *    indicators + use-of-funds summary % + risks summary + documents LOCKED;
 *  - granted: + detailed use of funds, full risks, documents list (signed URLs via separate endpoint);
 *  - connected: + owner contact data (sensitive_personal).
 * Tabs: overview, impact, indicators, risks, documents.
 */
class OpportunityDetailResource extends JsonResource
{
    use FormatsOpportunity;

    public function __construct(Opportunity $resource, private readonly InvestorAccessLevel $accessLevel)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        $o->loadMissing(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'sdgs', 'organization', 'teaser', 'fundingRange', 'indicators.sdg', 'owner']);
        $viewer = $request->user();

        $granted = $this->accessLevel->atLeast(InvestorAccessLevel::Granted);
        $connected = $this->accessLevel->atLeast(InvestorAccessLevel::Connected);

        $disclosed = $o->indicators->filter(fn (ImpactIndicator $i): bool => $i->is_primary || $i->is_disclosed);
        $indicators = $granted ? $o->indicators : $disclosed;

        $data = $this->publicIdentity($o) + [
            'access_level' => $this->accessLevel->value,
            'access_level_label' => $this->accessLevel->label(),
            'teaser' => $o->teaser ? ['summary' => $o->teaser->summary(), 'highlights' => $o->teaser->highlights ?? [], 'version' => $o->teaser->version] : null,
            'short_description' => $o->short_description,
            'social_links' => $o->social_links,
            'overview' => [
                'problem_statement' => $o->problem_statement,
                'solution' => $o->solution,
                'expected_change' => $o->expected_change,
                'revenue_model_type' => $o->revenue_model_type,
                'revenue_state' => $o->revenue_state?->value,
                'revenue_state_label' => $o->revenue_state?->label(),
            ],
            'impact' => [
                'beneficiaries_desc' => $o->beneficiaries_desc,
                'beneficiaries_current' => $o->beneficiaries_current,
                'beneficiaries_target' => $o->beneficiaries_target,
                'beneficiary_groups' => $o->beneficiaryGroups->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
                'sdgs' => $o->sdgs->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all(),
                'primary_indicator' => $this->indicator($o->indicators->firstWhere('is_primary', true)),
            ],
            'indicators' => $indicators->map(fn (ImpactIndicator $i): array => $this->indicator($i))->values()->all(),
            'use_of_funds' => $granted ? ($o->use_of_funds ?? []) : $this->useOfFundsSummary($o),
            'risks' => $granted ? $this->risksFull($o) : $this->risksSummary($o),
            'documents' => $granted
                ? ['locked' => false, 'items' => DocumentResource::collection($o->documents()->clean()->where('status', '!=', 'rejected')->get())->resolve($request)]
                : ['locked' => true, 'items' => [], 'reason' => __('messages.documents.locked')],
            'is_saved' => $this->when($viewer !== null && $viewer->isInvestor(), fn () => $o->savedBy()->where('user_id', $viewer->id)->exists()),
            'interest' => $this->when($viewer !== null && $viewer->isInvestor(), function () use ($o, $viewer): ?array {
                $interest = $o->interests()->where('investor_user_id', $viewer->id)->latest('id')->first();

                return $interest ? ['id' => $interest->id, 'status' => $interest->status->value, 'status_label' => $interest->status->label(), 'owner_decision' => $interest->owner_decision?->value] : null;
            }),
            'nda_required' => $this->when($viewer !== null && $viewer->isInvestor(), fn () => ! $granted && $o->fileAccessGrants()->where('investor_user_id', $viewer->id)->whereNotNull('granted_by_owner_at')->whereNull('nda_consent_id')->whereNull('revoked_at')->exists()),
        ] + $this->fundingBlock($o, $granted);

        // sensitive_personal — only when the interest is `connected` (MSG-01).
        if ($connected) {
            $data['owner_contact'] = [
                'name' => $o->owner?->name,
                'email' => $o->owner?->email,
                'phone' => $o->owner?->phone,
                'preferred_contact' => $o->owner?->preferred_contact,
            ];
        }

        return $data;
    }
}
