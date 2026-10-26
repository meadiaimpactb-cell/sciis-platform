<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

/** RPT-05 — weekly / monthly e-mail of a saved report (frequency null = unschedule). */
class ScheduleReportRequest extends ReportingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $on = filled($this->input('frequency'));

        return [
            'frequency' => ['present', 'nullable', 'in:weekly,monthly'],
            'format' => [$on ? 'required' : 'sometimes', 'in:xlsx,pdf'],
            'recipients' => [$on ? 'required' : 'sometimes', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }
}
