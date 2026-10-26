<?php

declare(strict_types=1);

namespace App\Http\Requests\Agreements;

/** AGR-03 — optional note sent with a review request. */
class AgreementNoteRequest extends AgreementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:'.self::NOTE_MAX]];
    }
}
