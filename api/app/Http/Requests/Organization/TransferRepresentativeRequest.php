<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Http\Requests\ApiRequest;

/** ACC-08 — hand the representative role to a colleague. */
class TransferRepresentativeRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
