<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum IndicatorValueStatus: string
{
    use HasLabel;

    case Entered = 'entered';
    case Verified = 'verified';
    case NeedsClarification = 'needs_clarification';
}
