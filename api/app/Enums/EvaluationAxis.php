<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum EvaluationAxis: string
{
    use HasLabel;

    case Readiness = 'readiness';
    case Feasibility = 'feasibility';
    case Impact = 'impact';
}
