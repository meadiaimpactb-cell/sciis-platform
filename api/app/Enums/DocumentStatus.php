<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum DocumentStatus: string
{
    use HasLabel;

    case PendingReview = 'pending_review';
    case Accepted = 'accepted';
    case Required = 'required';
    case Rejected = 'rejected';
}
