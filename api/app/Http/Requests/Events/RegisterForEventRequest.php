<?php

declare(strict_types=1);

namespace App\Http\Requests\Events;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\NormalizesPhone;
use App\Support\PhoneNumbers;

/** MTG-04 — public registration form (same honeypot `website` as the contact form CMS-05; phone optional, E.164). */
class RegisterForEventRequest extends ApiRequest
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
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', PhoneNumbers::rule()],
            'organization' => ['nullable', 'string', 'max:190'],
            'website' => ['prohibited'], // honeypot
        ];
    }
}
