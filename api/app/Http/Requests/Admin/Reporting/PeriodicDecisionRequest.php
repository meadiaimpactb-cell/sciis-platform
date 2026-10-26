<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

/** Journey 11.10 — approve (optional note), return to draft (note required), publish (audience). */
class PeriodicDecisionRequest extends ReportingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => $this->routeEndsWith('.return') ? ['required', 'string', 'min:5', 'max:2000'] : ['sometimes', 'nullable', 'string', 'max:2000'],
            'audience' => ['sometimes', 'nullable', 'in:staff,public'],
        ];
    }
}
