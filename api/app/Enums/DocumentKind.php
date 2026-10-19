<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum DocumentKind: string
{
    use HasLabel;

    case PitchDeck = 'pitch_deck';
    case CommercialRegister = 'commercial_register';
    case License = 'license';
    case FinancialStatements = 'financial_statements';
    case BusinessPlan = 'business_plan';
    case TeamCv = 'team_cv';
    case ImpactEvidence = 'impact_evidence';
    case AuthorizationLetter = 'authorization_letter';
    case FinancialProjections = 'financial_projections';
    case Agreement = 'agreement';
    case ProgressEvidence = 'progress_evidence';
    case Other = 'other';
}
