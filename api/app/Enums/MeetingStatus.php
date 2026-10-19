<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum MeetingStatus: string
{
    use HasLabel;

    case Scheduled = 'scheduled';
    case Held = 'held';
    case Cancelled = 'cancelled';
}
