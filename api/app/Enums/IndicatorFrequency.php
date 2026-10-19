<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum IndicatorFrequency: string
{
    use HasLabel;

    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';
}
