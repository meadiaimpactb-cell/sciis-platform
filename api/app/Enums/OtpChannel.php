<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum OtpChannel: string
{
    use HasLabel;

    case Email = 'email';
    case Sms = 'sms';

    /** Values accepted from clients: `email`, `phone` (standard contract) and the legacy alias `sms`. */
    public const INPUT_VALUES = ['email', 'phone', 'sms'];

    /** Maps client input (`email` | `phone` | `sms`) to the channel; null when unknown. */
    public static function fromInput(mixed $value): ?self
    {
        return match (is_string($value) ? strtolower(trim($value)) : null) {
            'email' => self::Email,
            'phone', 'sms' => self::Sms,
            default => null,
        };
    }

    /** Name used by the standard client contract (`email` | `phone`). */
    public function publicName(): string
    {
        return $this === self::Sms ? 'phone' : 'email';
    }
}
