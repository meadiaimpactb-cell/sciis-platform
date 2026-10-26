<?php

declare(strict_types=1);

namespace App\Services\Agreements\Signatures;

use App\Enums\AgreementParty;
use App\Models\Agreement;
use App\Models\AgreementVersion;
use App\Models\User;

/** AGR-05 — what a signer is asked to sign: the version (its text and SHA-256), the party and the consent shown. */
final class SignatureRequest
{
    public function __construct(
        public readonly Agreement $agreement,
        public readonly AgreementVersion $version,
        public readonly User $signer,
        public readonly AgreementParty $party,
        public readonly string $consentText,
        public readonly string $locale,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
    ) {}

    public function textHash(): string
    {
        return (string) $this->version->text_hash;
    }
}
