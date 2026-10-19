<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum RiskAppetite: string
{
    use HasLabel;

    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
