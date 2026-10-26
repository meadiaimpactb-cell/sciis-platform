<?php

declare(strict_types=1);

namespace App\Http\Requests\Agreements;

/**
 * AGR-05 — click-to-sign: explicit consent, plus the version number and SHA-256 the signer was shown (the API refuses
 * the signature if the text changed in the meantime).
 */
class SignAgreementRequest extends AgreementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'consent' => ['accepted'],
            'version' => ['required', 'integer', 'min:1'],
            'text_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
            'locale' => ['nullable', 'in:ar,en'],
        ];
    }
}
