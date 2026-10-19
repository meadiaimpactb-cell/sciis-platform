<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum InvestorAccessLevel: string
{
    use HasLabel;

    case Visitor = 'visitor';
    case Registered = 'registered';
    case ApprovedMember = 'approved_member';
    case Granted = 'granted';
    case Connected = 'connected';

    /** Ordering per SPEC §1.3: visitor < registered < approved_member < granted < connected. */
    public function rank(): int
    {
        return match ($this) {
            self::Visitor => 0,
            self::Registered => 1,
            self::ApprovedMember => 2,
            self::Granted => 3,
            self::Connected => 4,
        };
    }

    public function atLeast(self $level): bool
    {
        return $this->rank() >= $level->rank();
    }
}
