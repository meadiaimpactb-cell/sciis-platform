<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum DataRequestType: string
{
    use HasLabel;

    case Access = 'access';
    case Rectify = 'rectify';
    case Erase = 'erase';
}
