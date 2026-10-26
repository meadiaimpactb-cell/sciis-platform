<?php

declare(strict_types=1);

namespace App\Http\Requests\Entrepreneur;

use App\Enums\FundingDisplay;
use App\Enums\OpportunityStatus;
use App\Enums\ProjectStage;
use App\Enums\RevenueState;
use App\Http\Requests\ApiRequest;
use App\Models\Opportunity;
use App\Rules\FormAnswers;
use Illuminate\Validation\Rule;

/**
 * ENT-01/05 — sections: basics, organization, team, impact, market, financials, motivation, disclosures.
 * ENT-05 field-level lock: while `needs_completion` only the fields listed in the open completion requests
 * (plus aliases / whole sections) are accepted; anything else answers 422 listing the locked fields.
 */
class UpdateOpportunityRequest extends ApiRequest
{
    /** Policy check runs before validation so a locked opportunity answers 403, not 422 (ENT-05). */
    public function authorize(): bool
    {
        $opportunity = $this->route('opportunity');

        return $opportunity === null || ($this->user()?->can('update', $opportunity) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'basics' => ['sometimes', 'array'],
            'basics.name' => ['sometimes', 'string', 'min:3', 'max:190'],
            'basics.short_description' => ['nullable', 'string', 'max:280'],
            // OPP-11 — optional English versions of the public card fields.
            'basics.name_en' => ['nullable', 'string', 'min:3', 'max:190'],
            'basics.short_description_en' => ['nullable', 'string', 'max:280'],
            'basics.sector_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'basics.stage' => ['nullable', 'string', $this->enumRule(ProjectStage::class)],
            'basics.website' => ['nullable', 'url', 'max:255'],
            'basics.social_links' => ['nullable', 'array'],
            'basics.social_links.*' => ['nullable', 'url', 'max:255'],
            'basics.region_ids' => ['nullable', 'array', 'max:14'],
            'basics.region_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
            'basics.investment_type_ids' => ['nullable', 'array', 'max:9'],
            'basics.investment_type_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

            'organization' => ['sometimes', 'array'],
            'organization.name' => ['nullable', 'string', 'max:190'],
            'organization.legal_form' => ['nullable', 'string', 'in:company,establishment,nonprofit,other'],
            'organization.cr_number' => ['nullable', 'string', 'max:30'],
            'organization.unified_number' => ['nullable', 'string', 'max:30'],
            'organization.license_number' => ['nullable', 'string', 'max:60'],
            'organization.founded_on' => ['nullable', 'date', 'before_or_equal:today'],
            'organization.employees_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'organization.website' => ['nullable', 'url', 'max:255'],
            'organization.region_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'organization.city' => ['nullable', 'string', 'max:100'],

            'impact' => ['sometimes', 'array'],
            'impact.problem_statement' => ['nullable', 'string', 'max:5000'],
            'impact.solution' => ['nullable', 'string', 'max:5000'],
            'impact.expected_change' => ['nullable', 'string', 'max:5000'],
            'impact.beneficiaries_desc' => ['nullable', 'string', 'max:3000'],
            'impact.beneficiaries_current' => ['nullable', 'integer', 'min:0'],
            'impact.beneficiaries_target' => ['nullable', 'integer', 'min:0'],
            'impact.beneficiary_group_ids' => ['nullable', 'array'],
            'impact.beneficiary_group_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
            'impact.sdg_ids' => ['nullable', 'array', 'max:17'],
            'impact.sdg_ids.*' => ['integer', 'exists:taxonomy_terms,id'],

            'financials' => ['sometimes', 'array'],
            'financials.revenue_model_type' => ['nullable', 'string', 'max:60'],
            'financials.revenue_model_desc' => ['nullable', 'string', 'max:3000'],
            'financials.revenue_state' => ['nullable', 'string', $this->enumRule(RevenueState::class)],
            'financials.last_year_revenue_band_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'financials.funding_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'financials.funding_amount_display' => ['nullable', 'string', $this->enumRule(FundingDisplay::class)],
            'financials.funding_range_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'financials.use_of_funds' => ['nullable', 'array', 'max:20'],
            'financials.use_of_funds.*.item' => ['required_with:financials.use_of_funds', 'string', 'max:190'],
            'financials.use_of_funds.*.percent' => ['required_with:financials.use_of_funds', 'numeric', 'min:0', 'max:100'],
            'financials.prior_funding' => ['nullable', 'boolean'],
            'financials.prior_funding_desc' => ['nullable', 'string', 'max:3000'],

            // Team & founders (confidential "س").
            'team' => ['sometimes', 'array'],
            'team.founders_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'team.team_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'team.founders_commitment' => ['nullable', 'string', Rule::in(Opportunity::FOUNDERS_COMMITMENTS)],
            'team.founders_commitment_note' => ['nullable', 'string', 'max:500'],
            'team.prior_startup_experience' => ['nullable', 'boolean'],
            'team.prior_startup_experience_desc' => ['nullable', 'string', 'max:2000'],
            'team.industry_experience' => ['nullable', 'boolean'],
            'team.industry_experience_desc' => ['nullable', 'string', 'max:2000'],
            'team.founders_known_years' => ['nullable', 'string', Rule::in(Opportunity::FOUNDERS_KNOWN_YEARS)],
            'team.has_advisory_board' => ['nullable', 'boolean'],
            'team.advisory_board_desc' => ['nullable', 'string', 'max:2000'],
            'team.dev_team_structure' => ['nullable', 'string', Rule::in(Opportunity::DEV_TEAM_STRUCTURES)],
            'team.founders_agreement_signed' => ['nullable', 'boolean'],
            'team.founders' => ['nullable', 'array', 'max:'.Opportunity::MAX_FOUNDERS],
            'team.founders.*.name' => ['required', 'string', 'max:120'],
            'team.founders.*.role' => ['nullable', 'string', 'max:120'],
            'team.founders.*.linkedin_url' => ['nullable', 'url', 'max:255'],
            'team.founders.*.is_full_time' => ['nullable', 'boolean'],

            // Market & product.
            'market' => ['sometimes', 'array'],
            'market.target_customers' => ['nullable', 'string', 'max:3000'],
            'market.market_size_desc' => ['nullable', 'string', 'max:3000'],
            'market.competitors' => ['nullable', 'string', 'max:3000'],
            'market.competitive_advantage' => ['nullable', 'string', 'max:3000'],
            'market.go_to_market' => ['nullable', 'string', 'max:3000'],
            'market.product_stage' => ['nullable', 'string', Rule::in(Opportunity::PRODUCT_STAGES)],
            'market.technology_desc' => ['nullable', 'string', 'max:3000'],
            'market.traction_desc' => ['nullable', 'string', 'max:3000'],
            'market.scalability_desc' => ['nullable', 'string', 'max:3000'],

            // Motivation & commitment (internal "د").
            'motivation' => ['sometimes', 'array'],
            'motivation.why_now' => ['nullable', 'string', 'max:3000'],
            'motivation.support_needed' => ['nullable', 'array', 'max:'.count(Opportunity::SUPPORT_NEEDS)],
            'motivation.support_needed.*' => ['string', 'distinct', Rule::in(Opportunity::SUPPORT_NEEDS)],
            'motivation.program_expectations' => ['nullable', 'string', 'max:3000'],
            'motivation.how_heard_id' => ['nullable', 'integer', Rule::exists('taxonomy_terms', 'id')->where('group', 'how_heard')],

            'disclosures' => ['sometimes', 'array'],
            'disclosures.legal_disputes' => ['nullable', 'boolean'],
            'disclosures.legal_disputes_desc' => ['nullable', 'string', 'max:3000'],
            'disclosures.license_required' => ['nullable', 'boolean'],
            'disclosures.license_status' => ['nullable', 'string', 'max:40'],
            'disclosures.operational_risks' => ['nullable', 'string', 'max:5000'],
            'disclosures.council_relationship' => ['nullable', 'boolean'],
            'disclosures.council_relationship_desc' => ['nullable', 'string', 'max:2000'],
            'disclosures.data_accuracy_declared' => ['nullable', 'boolean'],
            'disclosures.teaser_publication_consent' => ['nullable', 'boolean'], // PRV-02: stored as a Consent record

            // FRM-01 — additional questions: types checked on every (auto)save, required ones are a checklist item.
            'extra_answers' => ['sometimes', 'nullable', 'array', new FormAnswers('opportunity', false)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            $rows = $this->input('financials.use_of_funds');
            if (is_array($rows) && $rows !== []) {
                $sum = array_sum(array_map(fn ($r): float => (float) ($r['percent'] ?? 0), $rows));
                if (abs($sum - 100) > 0.5) {
                    $v->errors()->add('financials.use_of_funds', __('validation.custom.use_of_funds.sum_100'));
                }
            }

            // The team includes the founders: team_size ≥ founders_count when both are sent.
            $founders = $this->input('team.founders_count');
            $size = $this->input('team.team_size');
            if (is_numeric($founders) && is_numeric($size) && (int) $size < (int) $founders) {
                $v->errors()->add('team.team_size', __('validation.custom.team_size.gte_founders'));
            }

            $locked = $this->lockedFields();
            if ($locked !== []) {
                $v->errors()->add('locked_fields', __('messages.opportunity.locked_fields', ['fields' => implode(', ', $locked)]));
                foreach ($locked as $path) {
                    $v->errors()->add($path, __('messages.opportunity.locked_fields', ['fields' => $path]));
                }
            }
        });
    }

    /**
     * ENT-05: submitted `section.field` paths that are NOT reopened while the opportunity is `needs_completion`.
     * Unchanged values are tolerated (the form re-sends whole sections), so only real edits are rejected.
     *
     * @return array<int, string>
     */
    public function lockedFields(): array
    {
        $opportunity = $this->route('opportunity');
        if (! $opportunity instanceof Opportunity || $opportunity->status !== OpportunityStatus::NeedsCompletion) {
            return [];
        }

        $locked = [];
        foreach (['basics', 'organization', 'team', 'impact', 'market', 'financials', 'motivation', 'disclosures'] as $section) {
            $payload = $this->input($section);
            if (! is_array($payload)) {
                continue;
            }
            foreach ($payload as $field => $value) {
                $field = (string) $field;
                $key = $section === 'organization' ? 'organization' : $field;
                if ($opportunity->isFieldEditable($key) || ($section === 'organization' && $opportunity->isFieldEditable('organization_id'))) {
                    continue;
                }
                if ($this->unchanged($opportunity, $section, $field, $value)) {
                    continue;
                }
                $locked[] = "{$section}.{$field}";
            }
        }

        return array_values(array_unique($locked));
    }

    /** True when the submitted value equals what is stored (so re-sending a full section is harmless). */
    private function unchanged(Opportunity $o, string $section, string $field, mixed $value): bool
    {
        $normalize = static function (mixed $v) use (&$normalize): mixed {
            if (is_array($v)) {
                $ints = array_values(array_filter($v, 'is_numeric'));
                if ($v !== [] && count($ints) === count($v)) {
                    $ints = array_map('intval', $ints);
                    sort($ints);

                    return $ints;
                }

                return array_map(fn ($x) => $normalize($x), $v);
            }
            if ($v === '' || $v === null) {
                return null;
            }
            if (is_bool($v)) {
                return $v;
            }
            if (is_numeric($v)) {
                return (float) $v;
            }

            return (string) $v;
        };

        $current = match (true) {
            $section === 'organization' => $o->organization?->getAttribute($field),
            $field === 'region_ids' => $o->regions()->pluck('taxonomy_terms.id')->all(),
            $field === 'investment_type_ids' => $o->investmentTypes()->pluck('taxonomy_terms.id')->all(),
            $field === 'beneficiary_group_ids' => $o->beneficiaryGroups()->pluck('taxonomy_terms.id')->all(),
            $field === 'sdg_ids' => $o->sdgs()->pluck('taxonomy_terms.id')->all(),
            $field === 'data_accuracy_declared' => $o->data_accuracy_declared_at !== null,
            $field === 'teaser_publication_consent' => $o->hasTeaserPublicationConsent(),
            default => $o->getAttribute($field),
        };

        // JSON lists: compare their canonical forms (key order / blank rows / selection order don't count as edits).
        if ($field === 'founders') {
            return Opportunity::normalizeFounders($current) === Opportunity::normalizeFounders($value);
        }
        if ($field === 'support_needed') {
            return Opportunity::normalizeSupportNeeded($current) === Opportunity::normalizeSupportNeeded($value);
        }

        if ($current instanceof \BackedEnum) {
            $current = $current->value;
        }
        if ($current instanceof \DateTimeInterface) {
            $current = $current->format('Y-m-d');
        }
        if (is_bool($current) && ! is_bool($value)) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }
        if ($current === null && is_bool($value) && $value === false) {
            // Unset boolean flags come back as `false` from the form.
            return true;
        }

        return $normalize($current) === $normalize($value);
    }
}
