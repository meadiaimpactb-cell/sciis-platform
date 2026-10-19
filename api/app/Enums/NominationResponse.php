<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum NominationResponse: string
{
    use HasLabel;

    case Interested = 'interested';
    case Declined = 'declined';
}
