<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\NormalizesCode;

/** Legacy POST /auth/verify-email and /auth/verify-phone — `code`: 6 digits. */
class VerifyOtpRequest extends ApiRequest
{
    use NormalizesCode;

    protected function prepareForValidation(): void
    {
        $this->normalizeCodeField('code');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'digits:'.(int) config('sciis.otp.length', 6)]];
    }
}
