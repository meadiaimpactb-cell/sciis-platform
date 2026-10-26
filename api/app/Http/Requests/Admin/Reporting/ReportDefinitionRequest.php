<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

use App\Services\Reporting\ReportDatasets;

/** RPT-04 — an ad-hoc report definition (preview body or export query string). */
class ReportDefinitionRequest extends ReportingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dataset' => ['required', 'string', 'in:'.implode(',', ReportDatasets::DATASETS)],
            'columns' => ['sometimes', 'array', 'max:30'],
            'columns.*' => ['string', 'max:40'],
            'filters' => ['sometimes', 'array'],
            'filters.status' => ['sometimes', 'nullable'],
            'filters.from' => ['sometimes', 'nullable', 'date'],
            'filters.to' => ['sometimes', 'nullable', 'date'],
            'filters.date_field' => ['sometimes', 'nullable', 'string', 'max:40'],
            'filters.sector' => ['sometimes', 'nullable', 'string', 'max:60'],
            'filters.region' => ['sometimes', 'nullable', 'string', 'max:60'],
            'filters.assignee' => ['sometimes', 'nullable', 'string', 'max:20'],
            'filters.investor_type' => ['sometimes', 'nullable'],
            'filters.overdue' => ['sometimes', 'nullable', 'boolean'],
            'group_by' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:40'],
            'dir' => ['sometimes', 'nullable', 'in:asc,desc'],
            'format' => ['sometimes', 'in:xlsx,pdf'],
        ];
    }

    /** @return array{columns: array<int, string>, filters: array<string, mixed>, group_by: string|null, sort: string|null, dir: string|null} */
    public function definition(): array
    {
        return [
            'columns' => array_values((array) $this->validated('columns', [])),
            'filters' => (array) $this->validated('filters', []),
            'group_by' => $this->validated('group_by'),
            'sort' => $this->validated('sort'),
            'dir' => $this->validated('dir'),
        ];
    }
}
