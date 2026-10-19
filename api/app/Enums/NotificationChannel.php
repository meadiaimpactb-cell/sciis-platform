<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum NotificationChannel: string
{
    use HasLabel;

    case Database = 'database';
    case Mail = 'mail';
    case Sms = 'sms';
}
