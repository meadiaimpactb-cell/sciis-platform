<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum OwnerDecision: string
{
    use HasLabel;

    case Approved = 'approved';
    case Declined = 'declined';
}
