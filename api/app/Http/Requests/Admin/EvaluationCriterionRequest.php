<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\EvaluationAxis;
use App\Http\Requests\ApiRequest;

/** FRM-03 / EVL-01 */
class EvaluationCriterionRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $req = $isCreate ? 'required' : 'sometimes';

        return [
            'axis' => [$req, 'string', $this->enumRule(EvaluationAxis::class)],
            'key' => [$req, 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'label_ar' => [$req, 'string', 'max:190'],
            'label_en' => ['nullable', 'string', 'max:190'],
            'weight' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'scale_descriptions' => [$req, 'array', 'size:5'],
            'scale_descriptions.*' => ['string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
