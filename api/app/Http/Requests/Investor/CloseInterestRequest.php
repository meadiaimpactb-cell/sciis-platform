<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Http\Requests\ApiRequest;

class CloseInterestRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
