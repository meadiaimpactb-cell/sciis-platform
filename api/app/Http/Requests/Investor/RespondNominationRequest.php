<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Http\Requests\ApiRequest;

class RespondNominationRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'in:interested,declined'],
            'decline_reason_id' => ['required_if:response,declined', 'nullable', 'integer', 'exists:taxonomy_terms,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
