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
 *    indicators + use-of-funds summary % + risks summary + public market subset (product_stage, target_customers,
 *    competitive_advantage, traction_desc) + documents LOCKED; `team` is null (confidential);
 *  - granted: + detailed use of funds, full risks, team & founders (`team`), documents list (signed URLs via separate endpoint);
 *  - connected: + owner contact data (sensitive_personal).
 * Tabs: overview, impact, indicators, risks, documents.
 *
 * The nested blocks (`overview`, `impact`, `indicators`, `risks`, `interest`) are kept; the flat aliases
 * (`problem_statement`, `primary_indicator`, `disclosed_indicators`, `risks_summary`, `risks_full`,
 * `my_interest`, `my_nomination`, …) are what the web/mobile detail pages read.
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
        $isInvestor = $viewer !== null && $viewer->isInvestor();

        $granted = $this->accessLevel->atLeast(InvestorAccessLevel::Granted);
        $connected = $this->accessLevel->atLeast(InvestorAccessLevel::Connected);

        $primary = $o->indicators->firstWhere('is_primary', true);
        $disclosed = $o->indicators->filter(fn (ImpactIndicator $i): bool => $i->is_primary || $i->is_disclosed);
        $indicators = $granted ? $o->indicators : $disclosed;
        $beneficiaryGroups = $o->beneficiaryGroups->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all();
        $sdgs = $o->sdgs->map(fn (TaxonomyTerm $t): array => $this->term($t))->values()->all();

        $overview = [
            'problem_statement' => $o->problem_statement,
            'solution' => $o->solution,
            'expected_change' => $o->expected_change,
            'revenue_model_type' => $o->revenue_model_type,
            'revenue_model_desc' => $granted ? $o->revenue_model_desc : null,
            'revenue_state' => $o->revenue_state?->value,
            'revenue_state_label' => $o->revenue_state?->label(),
        ];
        $impact = [
            'beneficiaries_desc' => $o->beneficiaries_desc,
            'beneficiaries_current' => $o->beneficiaries_current,
            'beneficiaries_target' => $o->beneficiaries_target,
            'beneficiary_groups' => $beneficiaryGroups,
            'sdgs' => $sdgs,
            'primary_indicator' => $this->indicator($primary),
        ];

        // Market & product: the public subset only (product stage, customers, advantage, traction) — same levels as
        // problem/solution. The rest of the market answers stay staff/owner-only (OpportunityFullResource).
        $market = $this->marketBlock($o, publicOnly: true);

        $myInterest = $isInvestor ? $this->myInterest($o, $viewer) : null;
        $myNomination = $isInvestor ? $this->myNomination($o, $viewer) : null;

        // OPP-11 — English name / description when the locale is `en` (Arabic fallback); the teaser localizes itself.
        $data = ['name' => $o->displayName()] + $this->publicIdentity($o) + $this->teaserBlock($o) + [
            'access_level' => $this->accessLevel->value,
            'access_level_label' => $this->accessLevel->label(),
            'short_description' => $o->displayShortDescription(),
            'social_links' => $o->social_links,
            'overview' => $overview,
            'impact' => $impact,
            'market' => $market,
            // Team & founders are confidential ("س"): only after the owner's grant (+ NDA), never before.
            'team' => $granted ? $this->teamBlock($o) : null,
            'indicators' => $indicators->map(fn (ImpactIndicator $i): array => $this->indicator($i))->values()->all(),
            // Flat aliases (web `OpportunityDetail`).
            'primary_indicator' => $this->indicator($primary),
            'disclosed_indicators' => $indicators->filter(fn (ImpactIndicator $i): bool => ! $i->is_primary)->map(fn (ImpactIndicator $i): array => $this->indicator($i))->values()->all(),
            'use_of_funds' => $granted ? ($o->use_of_funds ?? []) : $this->useOfFundsSummary($o),
            'risks' => $granted ? $this->risksFull($o) : $this->risksSummary($o),
            'risks_summary' => $this->risksSummaryText($o),
            'risks_full' => $granted ? $this->risksFullNested($o) : null,
            'documents' => $granted
                ? ['locked' => false, 'items' => DocumentResource::collection($o->documents()->clean()->where('status', '!=', 'rejected')->get())->resolve($request)]
                : ['locked' => true, 'items' => [], 'reason' => __('messages.documents.locked')],
            'is_saved' => $this->when($isInvestor, fn () => $o->savedBy()->where('user_id', $viewer->id)->exists()),
            'interest' => $this->when($isInvestor, fn () => $myInterest),
            'my_interest' => $this->when($isInvestor, fn () => $myInterest),
            'my_nomination' => $this->when($isInvestor, fn () => $myNomination),
            'nda_required' => $this->when($isInvestor, fn () => ! $granted && $o->fileAccessGrants()->where('investor_user_id', $viewer->id)->whereNotNull('granted_by_owner_at')->whereNull('nda_consent_id')->whereNull('revoked_at')->exists()),
        ] + $overview + $impact + $market + $this->fundingBlock($o, $granted);

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

    /** @return array<string, mixed>|null */
    private function myInterest(Opportunity $o, \App\Models\User $viewer): ?array
    {
        $interest = $o->interests()->where('investor_user_id', $viewer->id)->latest('id')->first();
        if ($interest === null) {
            return null;
        }

        $grant = $o->fileAccessGrants()->with('ndaConsent')->where('investor_user_id', $viewer->id)->whereNull('revoked_at')->latest('id')->first();
        $status = $interest->status->value;
        $nextKey = 'messages.interest.next_step.'.$status;

        return [
            'id' => $interest->id,
            'status' => $status,
            'status_label' => $interest->status->label(),
            'owner_decision' => $interest->owner_decision?->value,
            'owner_decided_at' => $interest->owner_decided_at?->toIso8601String(),
            'nda_accepted_at' => $grant?->ndaConsent?->accepted_at?->toIso8601String(),
            'next_step' => __($nextKey) !== $nextKey ? __($nextKey) : null,
            'created_at' => $interest->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function myNomination(Opportunity $o, \App\Models\User $viewer): ?array
    {
        $n = $o->nominations()->where('investor_user_id', $viewer->id)->latest('id')->first();
        if ($n === null) {
            return null;
        }

        return [
            'id' => $n->id,
            'match_score' => $n->match_score,
            'matched_criteria' => (array) $n->matched_criteria,
            'rationale' => $n->rationale,
            'teaser_version' => $n->teaser_version,
            'sent_at' => $n->sent_at?->toIso8601String(),
            'response' => $n->response?->value,
            'responded_at' => $n->responded_at?->toIso8601String(),
        ];
    }
}
