<?php

declare(strict_types=1);

namespace App\Http\Requests\Entrepreneur;

use App\Enums\ProjectStage;
use App\Http\Requests\ApiRequest;

/** ENT-01 — a draft is created with the basics; `{}` is accepted (the controller names it "مشروع جديد"). */
class StoreOpportunityRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'min:3', 'max:190'],
            'short_description' => ['nullable', 'string', 'max:280'],
            'name_en' => ['nullable', 'string', 'min:3', 'max:190'], // OPP-11
            'short_description_en' => ['nullable', 'string', 'max:280'],
            'sector_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'stage' => ['nullable', 'string', $this->enumRule(ProjectStage::class)],
        ];
    }
}
