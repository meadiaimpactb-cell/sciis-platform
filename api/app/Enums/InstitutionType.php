<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum InstitutionType: string
{
    use HasLabel;

    case Company = 'company';
    case Fund = 'fund';
    case FamilyOffice = 'family_office';
    case Donor = 'donor';
    case Nonprofit = 'nonprofit';
    case Other = 'other';
}
