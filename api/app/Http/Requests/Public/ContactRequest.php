<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Http\Requests\ApiRequest;

/** CMS-05 — honeypot field `website` must stay empty. */
class ContactRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:investor,entrepreneur,partnership,media,other'],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            'website' => ['prohibited'], // honeypot
        ];
    }
}
