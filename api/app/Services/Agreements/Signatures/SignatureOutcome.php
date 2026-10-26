<?php

declare(strict_types=1);

namespace App\Services\Agreements\Signatures;

use Carbon\CarbonImmutable;

/** AGR-05 — result of SignatureProvider::request(): completed now, or pending at the provider (redirect the signer). */
final class SignatureOutcome
{
    private function __construct(
        public readonly bool $completed,
        public readonly ?string $reference,
        public readonly ?string $redirectUrl,
        public readonly ?CarbonImmutable $signedAt,
    ) {}

    public static function completed(?string $reference = null, ?CarbonImmutable $signedAt = null): self
    {
        return new self(true, $reference, null, $signedAt ?? CarbonImmutable::now());
    }

    public static function pending(string $redirectUrl, ?string $reference = null): self
    {
        return new self(false, $reference, $redirectUrl, null);
    }
}
