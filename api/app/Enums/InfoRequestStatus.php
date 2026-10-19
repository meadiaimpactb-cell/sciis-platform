<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum InfoRequestStatus: string
{
    use HasLabel;

    case Sent = 'sent';
    case Processing = 'processing';
    case Answered = 'answered';
    case Closed = 'closed';
}
