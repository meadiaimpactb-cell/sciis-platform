<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\EvaluationResult;
use App\Http\Requests\ApiRequest;

/** EVL-03 */
class DecideEvaluationRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'result' => ['required', 'string', $this->enumRule(EvaluationResult::class)],
            'conditions' => ['required_if:result,eligible_with_conditions', 'nullable', 'string', 'max:3000'],
            'reasons' => ['required_if:result,not_eligible_now', 'nullable', 'string', 'max:3000'],
        ];
    }
}
