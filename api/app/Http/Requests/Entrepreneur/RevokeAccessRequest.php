<?php

declare(strict_types=1);

namespace App\Http\Requests\Entrepreneur;

use App\Http\Requests\ApiRequest;

/** ENT-06 — owner revokes a previously approved full-file access (reason optional, shown to staff). */
class RevokeAccessRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
