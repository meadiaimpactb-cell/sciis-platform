<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\EvaluationResult;
use App\Http\Requests\ApiRequest;

/** EVL-01 */
class ScoreRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scores' => ['required', 'array', 'min:1'],
            'scores.*.score' => ['required', 'integer', 'min:1', 'max:5'],
            'scores.*.note' => ['nullable', 'string', 'max:1000'],
            'recommendation' => ['required', 'string', $this->enumRule(EvaluationResult::class)],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
