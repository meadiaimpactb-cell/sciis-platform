<?php

declare(strict_types=1);

namespace App\Http\Requests\Entrepreneur;

use App\Http\Requests\ApiRequest;

/** ENT-06 */
class DecideAccessRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approve,decline'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'decline_reason_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
        ];
    }
}
