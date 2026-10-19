<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum InvestmentType: string
{
    use HasLabel;

    case Equity = 'equity';
    case Debt = 'debt';
    case PayForResults = 'pay_for_results';
    case Grant = 'grant';
    case Guarantee = 'guarantee';
    case PatientCapital = 'patient_capital';
    case Convertible = 'convertible';
    case RevenueShare = 'revenue_share';
    case Blended = 'blended';
}
