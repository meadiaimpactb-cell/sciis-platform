<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

class ResendOtpRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['channel' => ['required', 'string', 'in:email,sms']];
    }
}
