<?php

declare(strict_types=1);

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\ApiRequest;

/** PRJ-01 — a milestone of the plan proposed by the owner (deliverable, due date, responsibility, budget share %). */
class MilestoneRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:3', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'deliverable' => [$required, 'string', 'min:3', 'max:1000'],
            'responsible' => ['nullable', 'string', 'max:190'],
            'budget_share' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'due_at' => [$required, 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }
}
