<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\UserType;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rules\Password;

/** ACC-01 */
class RegisterRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $types = array_filter(UserType::values(), fn (string $t): bool => $t !== UserType::Staff->value);

        return [
            'type' => ['required', 'string', 'in:'.implode(',', $types)],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/', 'unique:users,phone'],
            'password' => ['required', 'string', Password::min(10)->mixedCase()->numbers()],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            'organization_name' => ['required_if:type,investor_institutional,entrepreneur_entity', 'nullable', 'string', 'max:190'],
            'consents' => ['required', 'array', 'min:2'],
            'consents.*' => ['string', 'in:privacy_policy,terms'],
            'consents_version' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            $consents = (array) $this->input('consents', []);
            if (! in_array('privacy_policy', $consents, true) || ! in_array('terms', $consents, true)) {
                $v->errors()->add('consents', __('validation.custom.consents.required_both'));
            }
        });
    }
}
