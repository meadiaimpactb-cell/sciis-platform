<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum AccessPolicy: string
{
    use HasLabel;

    case NominationOnly = 'nomination_only';
    case BrowseAll = 'browse_all';
    case Both = 'both';
}
