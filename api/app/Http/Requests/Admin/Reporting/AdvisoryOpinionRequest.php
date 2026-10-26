<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

/** EVL-04 — the advisory member's opinion. */
class AdvisoryOpinionRequest extends ReportingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['opinion' => ['required', 'string', 'min:10', 'max:5000']];
    }
}
