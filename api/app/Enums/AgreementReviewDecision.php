<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** AGR-03 — legal reviewer's decision on one agreement version. */
enum AgreementReviewDecision: string
{
    use HasLabel;

    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
}
