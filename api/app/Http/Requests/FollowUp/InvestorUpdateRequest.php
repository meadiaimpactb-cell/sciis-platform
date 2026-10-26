<?php

declare(strict_types=1);

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\ApiRequest;

/** ENT-10 — owner update to the connected investors: text, optional attachment, optional verified-indicator snapshot. */
class InvestorUpdateRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'min:3', 'max:190'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'include_indicators' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'max:'.((int) setting('documents.max_size_mb', 25) * 1024)],
        ];
    }
}
