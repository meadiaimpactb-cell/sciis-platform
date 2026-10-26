<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Enums\RiskAppetite;
use App\Http\Requests\ApiRequest;
use App\Rules\FormAnswers;

/** INV-01 — sections: basics, interests, experience, declarations. */
class UpdateProfileRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'basics' => ['sometimes', 'array'],
            'basics.country' => ['nullable', 'string', 'max:80'],
            'basics.city' => ['nullable', 'string', 'max:100'],
            'basics.employer' => ['nullable', 'string', 'max:190'],
            'basics.job_title' => ['nullable', 'string', 'max:190'],
            'basics.linkedin_url' => ['nullable', 'url', 'max:255'],
            'basics.preferred_contact' => ['nullable', 'string', 'in:email,phone,whatsapp'],
            'basics.how_heard_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],

            'interests' => ['sometimes', 'array'],
            'interests.sector_ids' => ['nullable', 'array'],
            'interests.sector_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
            'interests.stage_ids' => ['nullable', 'array'],
            'interests.stage_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
            'interests.investment_type_ids' => ['nullable', 'array'],
            'interests.investment_type_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
            'interests.region_ids' => ['nullable', 'array'],
            'interests.region_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
            'interests.cause_ids' => ['nullable', 'array'],
            'interests.cause_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
            'interests.risk_appetite' => ['nullable', 'string', $this->enumRule(RiskAppetite::class)],
            'interests.investment_range_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'interests.annual_investment_band_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],

            'experience' => ['sometimes', 'array'],
            'experience.experience_years' => ['nullable', 'integer', 'min:0', 'max:80'],
            'experience.experience_desc' => ['nullable', 'string', 'max:3000'],
            'experience.prior_investments_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'experience.prior_investments_desc' => ['nullable', 'string', 'max:3000'],
            'experience.has_social_investments' => ['nullable', 'boolean'],
            'experience.social_investments_desc' => ['nullable', 'string', 'max:3000'],
            'experience.mentoring_available' => ['nullable', 'boolean'],
            'experience.mentoring_areas' => ['nullable', 'string', 'max:1000'],
            // INV-01 — institutional investors only (ignored for individuals by InvestorProfileService).
            'experience.impact_policy' => ['nullable', 'string', 'max:3000'],

            'declarations' => ['sometimes', 'array'],
            'declarations.accredited_declaration' => ['nullable', 'array'],
            'declarations.conflicts_disclosure' => ['nullable', 'string', 'max:3000'],
            'declarations.commit_meetings' => ['nullable', 'boolean'],

            // FRM-01 — admin-defined additional questions, validated against the active schema (required enforced).
            'extra_answers' => ['sometimes', 'nullable', 'array', new FormAnswers('investor_profile')],
        ];
    }
}
