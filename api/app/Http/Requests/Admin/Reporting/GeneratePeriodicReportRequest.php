<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

/** Journey 11.10 — draft a periodic report for the period containing `anchor_date` (default: today). */
class GeneratePeriodicReportRequest extends ReportingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period_type' => ['required', 'in:monthly,quarterly,annual'],
            'anchor_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'title' => ['sometimes', 'nullable', 'string', 'max:190'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
