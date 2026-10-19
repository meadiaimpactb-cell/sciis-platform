<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Http\Requests\ApiRequest;

class SubmitMembershipRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['accept_terms' => ['required', 'accepted'], 'terms_version' => ['nullable', 'string', 'max:20']];
    }
}
