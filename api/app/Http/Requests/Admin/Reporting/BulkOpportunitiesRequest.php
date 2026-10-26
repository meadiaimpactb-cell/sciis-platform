<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

/** ADM-08 — selected opportunities (uuids, max 100) + reviewer or automatic assignment. */
class BulkOpportunitiesRequest extends ReportingRequest
{
    protected function prepareForValidation(): void
    {
        // GET export: `uuids=a,b,c`.
        if (is_string($this->input('uuids'))) {
            $this->merge(['uuids' => array_values(array_filter(explode(',', (string) $this->input('uuids'))))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['string', 'uuid', 'distinct'],
            'auto' => ['sometimes', 'boolean'],
            'reviewer_user_id' => [$this->routeEndsWith('.assign') && ! $this->boolean('auto') ? 'required' : 'nullable', 'integer'],
            'format' => ['sometimes', 'in:xlsx,pdf'],
        ];
    }
}
