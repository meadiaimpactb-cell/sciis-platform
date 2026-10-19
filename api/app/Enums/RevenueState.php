<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum RevenueState: string
{
    use HasLabel;

    case None = 'none';
    case Early = 'early';
    case Regular = 'regular';
}
