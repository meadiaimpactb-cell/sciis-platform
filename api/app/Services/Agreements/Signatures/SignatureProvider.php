<?php

declare(strict_types=1);

namespace App\Services\Agreements\Signatures;

/**
 * AGR-05 — e-signature driver contract (config `sciis.agreements.signature_driver`, map `signature_drivers`).
 *
 * `simple` (default) is the click-to-sign simple electronic signature with an explicit consent. A licensed provider
 * implements the same contract: it may complete immediately, or return a pending outcome with the URL where the
 * signer completes the provider's flow; its callback then records the evidence through
 * AgreementService::recordSignature(). Nothing else in the platform depends on the concrete driver.
 */
interface SignatureProvider
{
    /** Stable driver key stored with every signature (`agreement_signatures.provider`). */
    public function key(): string;

    /** Asks for one party's signature on the exact version text described by the request. */
    public function request(SignatureRequest $request): SignatureOutcome;
}
