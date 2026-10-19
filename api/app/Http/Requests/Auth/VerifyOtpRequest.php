<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class VerifyOtpRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'digits:'.(int) config('sciis.otp.length', 6)]];
    }
}
