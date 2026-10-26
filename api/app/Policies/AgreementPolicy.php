<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AgreementParty;
use App\Enums\AgreementReviewDecision;
use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\User;

/**
 * AGR-04 — the project owner and the connected investor see their own agreement (read-only) once a version has been
 * legally approved; they sign only while it is sent for signature. Staff access is permission-based (controllers).
 */
class AgreementPolicy
{
    public function viewAsParty(User $user, Agreement $agreement, ?AgreementParty $as = null): bool
    {
        $party = $agreement->partyFor($user);
        if ($party === null || ($as !== null && $party !== $as)) {
            return false;
        }

        return $agreement->versions()->whereHas('reviews', fn ($q) => $q->where('decision', AgreementReviewDecision::Approved->value))->exists();
    }

    public function sign(User $user, Agreement $agreement): bool
    {
        return $agreement->partyFor($user) !== null && $agreement->status === AgreementStatus::SentForSignature;
    }
}
