<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\EvaluationResult;
use App\Http\Requests\ApiRequest;

/** EVL-03 — result + conditions / reasons; an improvement plan (≤20 items) with "not eligible now" or "eligible with conditions". */
class DecideEvaluationRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'result' => ['required', 'string', $this->enumRule(EvaluationResult::class)],
            'conditions' => ['required_if:result,eligible_with_conditions', 'nullable', 'string', 'max:3000'],
            'reasons' => ['required_if:result,not_eligible_now', 'nullable', 'string', 'max:3000'],
            'improvement_plan' => ['nullable', 'array', 'max:20', 'prohibited_if:result,eligible'],
            'improvement_plan.*.text' => ['required', 'string', 'min:3', 'max:500'],
            'improvement_plan.*.due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
