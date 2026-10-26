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
    // INV-01 — investor membership documents.
    case InvestorCv = 'investor_cv';
    case ImpactPolicy = 'impact_policy';
    // MTG-03 — attachment of an in-platform message.
    case MessageAttachment = 'message_attachment';

    /** INV-01 — kinds an investor uploads to the membership file, by investor type. @return list<self> */
    public static function forInvestor(bool $institutional): array
    {
        return $institutional
            ? [self::CommercialRegister, self::AuthorizationLetter, self::ImpactPolicy]
            : [self::InvestorCv];
    }
}
