<?php

declare(strict_types=1);

namespace App\Enums;

/** ACC-09 — lifecycle of one national identity verification request (Nafath). */
enum IdentityVerificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
