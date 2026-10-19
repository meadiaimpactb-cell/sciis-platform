<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum AssignmentStatus: string
{
    use HasLabel;

    case Assigned = 'assigned';
    case CoiDeclared = 'coi_declared';
    case Scored = 'scored';
    case Withdrawn = 'withdrawn';
}
