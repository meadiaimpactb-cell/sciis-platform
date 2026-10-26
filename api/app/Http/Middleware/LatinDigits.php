<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Platform rule: numbers are always stored and shown with Latin digits (0-9), in Arabic as in English.
 * Every incoming string (JSON body, form fields, query) has Arabic-Indic (٠-٩) and Persian (۰-۹) digits turned into
 * Latin ones, and the Arabic percent / decimal / thousands signs (٪ ٫ ٬) into % . , — before validation, so nothing
 * typed on a phone keyboard ever reaches the database in Arabic numerals. Passwords are left exactly as typed.
 */
class LatinDigits extends TransformsRequest
{
    /** @var list<string> */
    protected array $except = ['password', 'password_confirmation', 'current_password'];

    private const MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٪' => '%', '٫' => '.', '٬' => ',',
    ];

    protected function transform($key, $value)
    {
        if (! is_string($value) || in_array($key, $this->except, true)) {
            return $value;
        }

        return self::toLatin($value);
    }

    public static function toLatin(string $value): string
    {
        return strtr($value, self::MAP);
    }
}
