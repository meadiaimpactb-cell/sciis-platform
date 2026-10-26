<?php

declare(strict_types=1);

namespace App\Http\Requests\Agreements;

/** AGR-04 — cancelling an open agreement needs a reason (kept on the record and in the audit trail). */
class CancelAgreementRequest extends AgreementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:'.self::NOTE_MAX]];
    }
}
