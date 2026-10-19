<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ExecutionStatus: string
{
    use HasLabel;

    case OnTrack = 'on_track';
    case Delayed = 'delayed';
    case Stalled = 'stalled';
}
