<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ContactCategory: string
{
    use HasLabel;

    case Investor = 'investor';
    case Entrepreneur = 'entrepreneur';
    case Partnership = 'partnership';
    case Media = 'media';
    case Other = 'other';
}
