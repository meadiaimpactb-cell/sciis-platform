<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ConsentType;
use App\Models\Consent;
use App\Models\InvestorProfile;
use App\Models\TaxonomyTerm;
use App\Services\FormSchemaService;
use App\Services\InvestorProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvestorProfile
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
                'impact_policy' => $this->when($isSelf || $isStaff, $this->impact_policy),
                'cv_document_uuid' => $this->cvDocument?->uuid,
            ],
            'declarations' => [
                'accredited_declaration' => $this->when($isSelf, $this->accredited_declaration),
                'conflicts_disclosure' => $this->when($isSelf || $isStaff, $this->conflicts_disclosure),
                'commit_meetings' => $this->commit_meetings,
                'data_accuracy_declared_at' => $this->when($isSelf || $isStaff, fn () => $this->dataAccuracyDeclaredAt()),
            ],
            // INV-01 — membership file, every version (owner + membership reviewers only; files open via signed URL).
            'documents' => $this->when($isSelf || ($isStaff && $viewer->can('memberships.review')), fn () => DocumentResource::collection($this->resource->membershipDocuments())->resolve($request)),
            // FRM-01 — additional questions (owner prefills the form, staff read the labelled answers).
            'extra_answers' => $this->when($isSelf || $isStaff, fn () => app(FormSchemaService::class)->present($this->extra_answers)),
            'completion_percent' => $this->completion_percent,
            'missing_items' => InvestorProfileService::missingItems($this->resource),
            // INV-02 — "آخر تحديث".
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function dataAccuracyDeclaredAt(): ?string
    {
        $consent = Consent::query()
            ->where('user_id', $this->user_id)
            ->where('type', ConsentType::DataAccuracy->value)
            ->latest('accepted_at')
            ->first();

        return $consent?->accepted_at?->toIso8601String();
    }
}
