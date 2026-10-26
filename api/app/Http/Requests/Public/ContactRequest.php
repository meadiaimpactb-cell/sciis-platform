<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\NormalizesPhone;
use App\Support\PhoneNumbers;

/** CMS-05 — honeypot field `website` must stay empty. Optional phone is validated/stored like account phones (E.164 mobile). */
class ContactRequest extends ApiRequest
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
            'category' => ['required', 'string', 'in:investor,entrepreneur,partnership,media,other'],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', PhoneNumbers::rule()],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            'website' => ['prohibited'], // honeypot
        ];
    }
}
