<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Carbon\CarbonImmutable;

/** ACC-09 — a started verification request as returned by the provider. */
final class IdentityRequest
{
    public function __construct(
        public readonly string $transactionId,
        /** Number the user must pick in the provider's app (Nafath "random"). */
        public readonly ?string $random,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
