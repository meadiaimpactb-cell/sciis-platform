<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

/** MAT-01/03 */
class NominateRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'investor_user_ids' => ['required', 'array', 'min:1', 'max:10'],
            'investor_user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'rationale' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
