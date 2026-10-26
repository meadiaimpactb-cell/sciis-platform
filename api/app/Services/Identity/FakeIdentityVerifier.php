<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\IdentityVerificationStatus;
use Carbon\CarbonImmutable;

/**
 * ACC-09 — in-memory driver for tests and local demos (`SCIIS_IDENTITY_DRIVER=fake`, never in production).
 * Each status() call returns the next queued outcome (the last one repeats): e.g. [Pending, Approved].
 */
final class FakeIdentityVerifier implements IdentityVerifier
{
    /** @var list<IdentityVerificationStatus> */
    private array $outcomes;

    /** @var list<string> national IDs passed to start() */
    public array $started = [];

    /** @param list<IdentityVerificationStatus> $outcomes */
    public function __construct(array $outcomes = [IdentityVerificationStatus::Approved], private readonly string $random = '42')
    {
        $this->outcomes = $outcomes === [] ? [IdentityVerificationStatus::Approved] : array_values($outcomes);
    }

    public function driver(): string
    {
        return 'fake';
    }

    public function available(): bool
    {
        return true;
    }

    public function start(string $nationalId, string $locale): IdentityRequest
    {
        $this->started[] = $nationalId;

        return new IdentityRequest('fake-'.count($this->started), $this->random, CarbonImmutable::now()->addMinutes(3));
    }

    public function status(string $nationalId, IdentityRequest $request): IdentityVerificationStatus
    {
        return count($this->outcomes) > 1 ? array_shift($this->outcomes) : $this->outcomes[0];
    }
}
