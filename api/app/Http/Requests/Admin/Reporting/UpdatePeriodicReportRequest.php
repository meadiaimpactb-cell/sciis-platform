<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

/** Journey 11.10 — edit a draft's title, summary and intended audience. */
class UpdatePeriodicReportRequest extends ReportingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:3', 'max:190'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'audience' => ['sometimes', 'in:staff,public'],
        ];
    }
}
