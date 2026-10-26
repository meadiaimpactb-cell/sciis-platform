<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** ENT-10 — published right away, or held for council review first (`follow_up.updates_require_review`). */
enum InvestorUpdateStatus: string
{
    use HasLabel;

    case PendingReview = 'pending_review';
    case Published = 'published';
    case Rejected = 'rejected';
}
