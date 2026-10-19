<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

/** EVL-02 — exactly two. */
class AssignEvaluatorsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'evaluator_ids' => ['required', 'array', 'size:2'],
            'evaluator_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }
}
