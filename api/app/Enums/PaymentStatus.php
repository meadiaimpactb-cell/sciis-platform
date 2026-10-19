<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum PaymentStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
}
