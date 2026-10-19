<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ScanResult: string
{
    use HasLabel;

    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
}
