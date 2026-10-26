<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\IdentityVerificationStatus;

/**
 * ACC-09 (P3) — national identity verification adapter. Drivers (`SCIIS_IDENTITY_DRIVER`):
 *   none   (default) — not available; the account page hides the entry.
 *   nafath — Nafath MFA (Elm): the user opens the Nafath app and picks the number shown on the platform.
 *   fake   — local demos / tests only (refused in production).
 * Flow: start(national id) → request {transaction id, random, expires} → status() polled until final.
 */
interface IdentityVerifier
{
    public function driver(): string;

    /** True when the driver is configured and may be offered to users. */
    public function available(): bool;

    /** Opens a verification request for the national ID / iqama number. */
    public function start(string $nationalId, string $locale): IdentityRequest;

    /** Current status of a request opened by start(). */
    public function status(string $nationalId, IdentityRequest $request): IdentityVerificationStatus;
}
