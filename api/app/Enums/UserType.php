<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum UserType: string
{
    use HasLabel;

    case InvestorIndividual = 'investor_individual';
    case InvestorInstitutional = 'investor_institutional';
    case EntrepreneurIndividual = 'entrepreneur_individual';
    case EntrepreneurEntity = 'entrepreneur_entity';
    case Staff = 'staff';
}
