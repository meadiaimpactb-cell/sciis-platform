<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TaxonomyTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\InvestorProfile
 *
 * Confidential fields (investment range / annual band / accredited declaration) only for self & staff.
 * `accredited_declaration` is kept but NOT shown to anyone except the owner (doc §7.5).
 */
class InvestorProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isSelf = $viewer?->id === $this->user_id;
        $isStaff = $viewer?->isStaff() ?? false;
        $term = fn (?TaxonomyTerm $t): ?array => $t ? ['id' => $t->id, 'key' => $t->key, 'label' => $t->label()] : null;

        $this->loadMissing(['sectors', 'stages', 'investmentTypes', 'regions', 'causes', 'investmentRange', 'annualInvestmentBand', 'howHeard']);

        return [
            'basics' => [
                'country' => $this->country,
                'city' => $this->city,
                'employer' => $this->employer,
                'job_title' => $this->job_title,
                'linkedin_url' => $this->linkedin_url,
                'preferred_contact' => $this->preferred_contact,
                'how_heard' => $term($this->howHeard),
            ],
            'interests' => [
                'sectors' => $this->sectors->map($term)->values()->all(),
                'stages' => $this->stages->map($term)->values()->all(),
                'investment_types' => $this->investmentTypes->map($term)->values()->all(),
                'regions' => $this->regions->map($term)->values()->all(),
                'causes' => $this->causes->map($term)->values()->all(),
                'risk_appetite' => $this->risk_appetite?->value,
                'risk_appetite_label' => $this->risk_appetite?->label(),
                'investment_range' => $this->when($isSelf || $isStaff, $term($this->investmentRange)),
                'annual_investment_band' => $this->when($isSelf || $isStaff, $term($this->annualInvestmentBand)),
            ],
            'experience' => [
                'experience_years' => $this->experience_years,
                'experience_desc' => $this->experience_desc,
                'prior_investments_count' => $this->prior_investments_count,
                'prior_investments_desc' => $this->prior_investments_desc,
                'has_social_investments' => $this->has_social_investments,
                'social_investments_desc' => $this->social_investments_desc,
                'mentoring_available' => $this->mentoring_available,
                'mentoring_areas' => $this->mentoring_areas,
                'cv_document_uuid' => $this->cvDocument?->uuid,
            ],
            'declarations' => [
                'accredited_declaration' => $this->when($isSelf, $this->accredited_declaration),
                'conflicts_disclosure' => $this->when($isSelf || $isStaff, $this->conflicts_disclosure),
                'commit_meetings' => $this->commit_meetings,
            ],
            'completion_percent' => $this->completion_percent,
        ];
    }
}
