<?php

declare(strict_types=1);

namespace App\Http\Requests\Entrepreneur;

use App\Enums\FundingDisplay;
use App\Enums\ProjectStage;
use App\Enums\RevenueState;
use App\Http\Requests\ApiRequest;

/** ENT-01/05 — sections: basics, organization, impact, financials, disclosures. */
class UpdateOpportunityRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'basics' => ['sometimes', 'array'],
            'basics.name' => ['sometimes', 'string', 'min:3', 'max:190'],
            'basics.short_description' => ['nullable', 'string', 'max:280'],
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

            'disclosures' => ['sometimes', 'array'],
            'disclosures.legal_disputes' => ['nullable', 'boolean'],
            'disclosures.legal_disputes_desc' => ['nullable', 'string', 'max:3000'],
            'disclosures.license_required' => ['nullable', 'boolean'],
            'disclosures.license_status' => ['nullable', 'string', 'max:40'],
            'disclosures.operational_risks' => ['nullable', 'string', 'max:5000'],
            'disclosures.council_relationship' => ['nullable', 'boolean'],
            'disclosures.council_relationship_desc' => ['nullable', 'string', 'max:2000'],
            'disclosures.data_accuracy_declared' => ['nullable', 'boolean'],
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
        });
    }
}
