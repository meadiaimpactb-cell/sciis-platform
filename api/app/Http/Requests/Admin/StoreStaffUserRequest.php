<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rules\Password;

/** ACC-06 — staff accounts. */
class StoreStaffUserRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/', 'unique:users,phone'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'exists:roles,name'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
        ];
    }
}
