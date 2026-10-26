<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

use App\Services\Reporting\ReportDatasets;

/** RPT-04 — save / rename / redefine a named report. */
class SavedReportRequest extends ReportingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:3', 'max:150'],
            'dataset' => [$required, 'string', 'in:'.implode(',', ReportDatasets::DATASETS)],
            'definition' => [$required, 'array'],
            'definition.columns' => ['sometimes', 'array', 'max:30'],
            'definition.columns.*' => ['string', 'max:40'],
            'definition.filters' => ['sometimes', 'array'],
            'definition.filters.from' => ['sometimes', 'nullable', 'date'],
            'definition.filters.to' => ['sometimes', 'nullable', 'date'],
            'definition.group_by' => ['sometimes', 'nullable', 'string', 'max:40'],
            'definition.sort' => ['sometimes', 'nullable', 'string', 'max:40'],
            'definition.dir' => ['sometimes', 'nullable', 'in:asc,desc'],
        ];
    }
}
