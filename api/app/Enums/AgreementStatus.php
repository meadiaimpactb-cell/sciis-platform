<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum AgreementStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case LegalReview = 'legal_review';
    case Approved = 'approved';
    case Signed = 'signed';
    case Archived = 'archived';
}
