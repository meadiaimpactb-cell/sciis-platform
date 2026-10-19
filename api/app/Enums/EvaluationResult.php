<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum EvaluationResult: string
{
    use HasLabel;

    case Eligible = 'eligible';
    case EligibleWithConditions = 'eligible_with_conditions';
    case NotEligibleNow = 'not_eligible_now';
}
