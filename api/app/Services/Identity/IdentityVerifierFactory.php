<?php

declare(strict_types=1);

namespace App\Services\Identity;

/** ACC-09 — builds the configured identity verification driver (`sciis.identity.driver`). */
final class IdentityVerifierFactory
{
    public static function make(): IdentityVerifier
    {
        return match ((string) config('sciis.identity.driver', 'none')) {
            'nafath' => new NafathIdentityVerifier(
                (string) config('services.nafath.base_url', ''),
                (string) config('services.nafath.app_id', ''),
                (string) config('services.nafath.app_key', ''),
                (string) config('services.nafath.service', 'DigitalServiceEnrollmentWithoutBio'),
                (int) config('services.nafath.ttl_seconds', 180),
                (int) config('services.nafath.timeout', 10),
            ),
            // Local demos only — never offered in production.
            'fake' => app()->isProduction() ? new NoneIdentityVerifier : new FakeIdentityVerifier,
            default => new NoneIdentityVerifier,
        };
    }
}
