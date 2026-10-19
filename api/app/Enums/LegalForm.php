<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum LegalForm: string
{
    use HasLabel;

    case Company = 'company';
    case Establishment = 'establishment';
    case Nonprofit = 'nonprofit';
    case Other = 'other';
}
