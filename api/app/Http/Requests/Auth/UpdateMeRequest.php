<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class UpdateMeRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'locale' => ['sometimes', 'string', 'in:ar,en'],
            'preferred_contact' => ['sometimes', 'nullable', 'string', 'in:email,phone,whatsapp'],
        ];
    }
}
