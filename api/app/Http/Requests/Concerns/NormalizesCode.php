<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One-time codes (OTP / TOTP / recovery): accept Arabic-Indic & Persian digits and ignore
 * whitespace/dashes that users paste from SMS, mail or authenticator apps.
 *
 * @mixin FormRequest
 */
trait NormalizesCode
{
    protected function normalizeCodeField(string $field = 'code'): void
    {
        $value = $this->input($field);
        if (! is_string($value)) {
            return;
        }

        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        $this->merge([$field => (string) preg_replace('/[\s\x{00A0}\x{200E}\x{200F}\-]+/u', '', $value)]);
    }
}
