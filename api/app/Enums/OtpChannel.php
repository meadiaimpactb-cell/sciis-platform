<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum OtpChannel: string
{
    use HasLabel;

    case Email = 'email';
    case Sms = 'sms';
}
