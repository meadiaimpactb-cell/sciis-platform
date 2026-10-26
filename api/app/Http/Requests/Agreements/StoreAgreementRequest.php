<?php

declare(strict_types=1);

namespace App\Http\Requests\Agreements;

/** AGR-02 — draft an agreement for a connection from an active template; `placeholders` override the defaults. */
class StoreAgreementRequest extends AgreementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'interest_id' => ['required', 'integer', 'exists:interests,id'],
            'template_id' => ['required', 'integer', 'exists:agreement_templates,id'],
            'title' => ['nullable', 'string', 'min:3', 'max:190'],
            'placeholders' => ['nullable', 'array', 'max:60'],
            'placeholders.*' => ['nullable', 'string', 'max:500'],
        ];
    }
}
