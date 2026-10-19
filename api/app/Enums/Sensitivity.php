<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum Sensitivity: string
{
    use HasLabel;

    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case SensitivePersonal = 'sensitive_personal';

    /** Numeric rank — higher means more restricted. */
    public function rank(): int
    {
        return match ($this) {
            self::Public => 0,
            self::Internal => 1,
            self::Confidential => 2,
            self::SensitivePersonal => 3,
        };
    }

    /** Short Arabic marker from doc §6.2: ع / د / س / ح. */
    public function marker(): string
    {
        return match ($this) {
            self::Public => 'ع',
            self::Internal => 'د',
            self::Confidential => 'س',
            self::SensitivePersonal => 'ح',
        };
    }
}
