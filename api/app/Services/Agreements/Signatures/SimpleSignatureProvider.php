<?php

declare(strict_types=1);

namespace App\Services\Agreements\Signatures;

use Illuminate\Support\Str;

/**
 * AGR-05 — simple electronic signature: the signed-in, verified party ticks the consent and clicks "Sign". The evidence
 * (signer, time, IP, user agent, SHA-256 of the exact text, consent wording) is recorded by AgreementService.
 */
final class SimpleSignatureProvider implements SignatureProvider
{
    public function key(): string
    {
        return 'simple';
    }

    public function request(SignatureRequest $request): SignatureOutcome
    {
        return SignatureOutcome::completed('simple-'.Str::uuid()->toString());
    }
}
