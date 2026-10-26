<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

/** EVL-04 — refer a file to an advisory member: question + access expiry (at most 90 days ahead). */
class AdvisoryReferralRequest extends ReportingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'advisory_user_id' => ['required', 'integer', 'exists:users,id'],
            'question' => ['required', 'string', 'min:10', 'max:2000'],
            'expires_at' => ['required', 'date', 'after:today', 'before_or_equal:'.now()->addDays(90)->toDateString()],
        ];
    }
}
