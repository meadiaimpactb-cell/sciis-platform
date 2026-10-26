<?php

declare(strict_types=1);

namespace App\Http\Requests\Agreements;

use App\Enums\AgreementReviewDecision;

/** AGR-03 — approve the version under review, or request changes (comment required). */
class ReviewAgreementRequest extends AgreementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', $this->enumRule(AgreementReviewDecision::class)],
            'note' => ['nullable', 'required_if:decision,'.AgreementReviewDecision::ChangesRequested->value, 'string', 'min:3', 'max:'.self::NOTE_MAX],
        ];
    }
}
