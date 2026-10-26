<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\NormalizesCode;

/** /auth/2fa/confirm and /auth/2fa/challenge — `code`: 6-digit TOTP or a 10-character recovery code. */
class TwoFactorCodeRequest extends ApiRequest
{
    use NormalizesCode;

    protected function prepareForValidation(): void
    {
        $this->normalizeCodeField('code');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'min:6', 'max:12']];
    }
}
