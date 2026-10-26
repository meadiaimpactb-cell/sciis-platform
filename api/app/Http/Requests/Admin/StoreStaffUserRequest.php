<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\NormalizesPhone;
use App\Support\PhoneNumbers;
use Illuminate\Validation\Rules\Password;

/** ACC-06 — staff accounts. */
class StoreStaffUserRequest extends ApiRequest
{
    use NormalizesPhone;

    protected function prepareForValidation(): void
    {
        $this->normalizePhoneFields('phone');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', PhoneNumbers::rule(), 'unique:users,phone'],
            // Optional: when omitted the API generates a temporary password and emails a reset link.
            'password' => ['nullable', 'string', Password::defaults()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'exists:roles,name'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
        ];
    }
}
