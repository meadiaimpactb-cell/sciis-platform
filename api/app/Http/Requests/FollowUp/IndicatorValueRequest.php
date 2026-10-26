<?php

declare(strict_types=1);

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\ApiRequest;

/** IMP-02 — a measured indicator value for a period (value, dates, optional evidence file, note). */
class IndicatorValueRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', 'before_or_equal:today'],
            'value' => ['required', 'numeric', 'min:-99999999999999', 'max:99999999999999'],
            'note' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'max:'.((int) setting('documents.max_size_mb', 25) * 1024)],
        ];
    }
}
