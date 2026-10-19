<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum MeetingParticipantRole: string
{
    use HasLabel;

    case Investor = 'investor';
    case Owner = 'owner';
    case Council = 'council';
}
