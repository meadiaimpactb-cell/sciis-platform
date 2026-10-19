<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum PaymentPurpose: string
{
    use HasLabel;

    case MembershipFee = 'membership_fee';
    case ServiceFee = 'service_fee';
    case Training = 'training';
    case Sponsorship = 'sponsorship';
}
