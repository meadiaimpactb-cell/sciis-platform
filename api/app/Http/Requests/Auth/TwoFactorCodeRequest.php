<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class TwoFactorCodeRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // 6-digit TOTP or a 10-character recovery code.
        return ['code' => ['required', 'string', 'min:6', 'max:12']];
    }
}
