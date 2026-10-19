<?php

declare(strict_types=1);

namespace App\Http\Requests\Entrepreneur;

use App\Enums\IndicatorFrequency;
use App\Http\Requests\ApiRequest;

/** ENT-01 impact indicators (IMP). */
class StoreIndicatorRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:190'],
            'definition' => ['nullable', 'string', 'max:2000'],
            'unit' => ['nullable', 'string', 'max:60'],
            'baseline' => ['nullable', 'numeric'],
            'target' => ['nullable', 'numeric'],
            'data_source' => ['nullable', 'string', 'max:190'],
            'frequency' => ['nullable', 'string', $this->enumRule(IndicatorFrequency::class)],
            'verification_method' => ['nullable', 'string', 'max:190'],
            'sdg_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'is_primary' => ['nullable', 'boolean'],
            'is_disclosed' => ['nullable', 'boolean'],
        ];
    }
}
