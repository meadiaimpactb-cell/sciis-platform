<?php

declare(strict_types=1);

namespace App\Http\Requests\Agreements;

/** AGR-02 — every edit is a new version with a short reason. */
class ReviseAgreementRequest extends AgreementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:20', 'max:'.self::TEXT_MAX],
            'body_en' => ['nullable', 'string', 'max:'.self::TEXT_MAX],
            'change_note' => ['required', 'string', 'min:3', 'max:'.self::NOTE_MAX],
        ];
    }
}
