<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

/**
 * POST /auth/2fa/disable and DELETE /auth/2fa — BOTH the current password and a valid code
 * (TOTP or an unused recovery code) are required.
 */
class DisableTwoFactorRequest extends TwoFactorCodeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['password' => ['required', 'string', 'current_password']];
    }
}
