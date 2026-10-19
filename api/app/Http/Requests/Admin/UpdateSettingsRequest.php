<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

/** ADM-03 */
class UpdateSettingsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*' => ['nullable'],
            'settings.opportunity\.access_policy' => ['sometimes', 'string', 'in:nomination_only,browse_all,both'],
            'settings.opportunity\.funding_display' => ['sometimes', 'string', 'in:band,exact,hidden'],
            'settings.opportunity\.max_nominations_per_opportunity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'settings.sla\.review_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'settings.sla\.completion_days' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'settings.sla\.evaluation_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'settings.sla\.owner_decision_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'settings.documents\.max_size_mb' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'settings.documents\.allowed_mimes' => ['sometimes', 'array'],
            'settings.privacy\.retention_days\.rejected' => ['sometimes', 'integer', 'min:30'],
            'settings.privacy\.retention_days\.withdrawn' => ['sometimes', 'integer', 'min:30'],
            'settings.privacy\.retention_days\.inactive' => ['sometimes', 'integer', 'min:30'],
            'settings.membership\.fee_sar' => ['sometimes', 'numeric', 'min:0'],
            'settings.membership\.requires_prior_investments' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
