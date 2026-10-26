<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

/** ADM-08 — selected memberships (ids, max 100) + reviewer for assignment. */
class BulkMembershipsRequest extends ReportingRequest
{
    protected function prepareForValidation(): void
    {
        // GET export: `ids=1,2,3`.
        if (is_string($this->input('ids'))) {
            $this->merge(['ids' => array_values(array_filter(explode(',', (string) $this->input('ids'))))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'reviewer_user_id' => [$this->routeEndsWith('.assign') ? 'required' : 'nullable', 'integer'],
            'format' => ['sometimes', 'in:xlsx,pdf'],
        ];
    }
}
