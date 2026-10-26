<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\DomainException;

/** ACC-09 — default driver: identity verification is not offered. */
final class NoneIdentityVerifier implements IdentityVerifier
{
    public function driver(): string
    {
        return 'none';
    }

    public function available(): bool
    {
        return false;
    }

    public function start(string $nationalId, string $locale): IdentityRequest
    {
        throw new DomainException(__('messages.identity.unavailable'), 'identity_unavailable', 404);
    }

    public function status(string $nationalId, IdentityRequest $request): IdentityVerificationStatus
    {
        throw new DomainException(__('messages.identity.unavailable'), 'identity_unavailable', 404);
    }
}
