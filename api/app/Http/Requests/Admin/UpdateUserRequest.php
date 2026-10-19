<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

class UpdateUserRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
            'status' => ['sometimes', 'string', 'in:active,suspended'],
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
        ];
    }
}
