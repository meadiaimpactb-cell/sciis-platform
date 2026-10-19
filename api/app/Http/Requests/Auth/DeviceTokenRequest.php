<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class DeviceTokenRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'in:ios,android,web'],
            'token' => ['required', 'string', 'max:512'],
        ];
    }
}
