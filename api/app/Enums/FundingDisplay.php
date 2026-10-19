<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum FundingDisplay: string
{
    use HasLabel;

    case Exact = 'exact';
    case Band = 'band';
    case Hidden = 'hidden';
}
