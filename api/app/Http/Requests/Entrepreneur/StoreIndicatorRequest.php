<?php

declare(strict_types=1);

namespace App\Http\Requests\Entrepreneur;

use App\Enums\IndicatorFrequency;
use App\Http\Requests\ApiRequest;

/** ENT-01 impact indicators (IMP). IMP-03 — optional IRIS+ code (OD|PD|OI|PI|FP + 4 digits, e.g. PI1234, OI4060). */
class StoreIndicatorRequest extends ApiRequest
{
    public const IRIS_CODE_PATTERN = '/^(OD|PD|OI|PI|FP)\d{4}$/';

    /** "pi 1234" / " oi4060 " → "PI1234" / "OI4060"; blank → null. */
    protected function prepareForValidation(): void
    {
        if ($this->has('iris_code')) {
            $code = strtoupper(preg_replace('/\s+/', '', (string) $this->input('iris_code')) ?? '');
            $this->merge(['iris_code' => $code === '' ? null : $code]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'iris_code' => ['nullable', 'string', 'max:40', 'regex:'.self::IRIS_CODE_PATTERN],
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
