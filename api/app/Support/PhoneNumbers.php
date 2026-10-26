<?php

declare(strict_types=1);

namespace App\Support;

use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;
use Throwable;

/**
 * Standard verification layer — mobile numbers (libphonenumber via propaganistas/laravel-phone).
 *
 * One place for the server-side phone policy, mirroring the web client:
 *  - parsed with libphonenumber, default region SA, international numbers allowed;
 *  - must be a real MOBILE number (no landlines, no "looks like digits" regex);
 *  - always stored as E.164 (`+9665XXXXXXXX`).
 *
 * Accepted input shapes (normalised before validation): `05XXXXXXXX`, `5XXXXXXXX`, `9665XXXXXXXX`,
 * `009665XXXXXXXX`, `+9665XXXXXXXX`, with spaces / dashes / dots / parentheses, Arabic-Indic or
 * Persian digits and bidi marks.
 */
final class PhoneNumbers
{
    public const DEFAULT_REGION = 'SA';

    /** Validation rule: a valid mobile number, any country, parsed with SA as the default region. */
    public static function rule(): Phone
    {
        return (new Phone)->country(self::DEFAULT_REGION)->international()->mobile();
    }

    /**
     * Normalises user input to E.164 when it is a valid number; otherwise returns the cleaned input
     * unchanged so the validation rule can reject it with the proper message.
     * Non-strings and blank strings are returned as-is (null/''), so `nullable` keeps working.
     */
    public static function normalize(mixed $raw, string $region = self::DEFAULT_REGION): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        $clean = self::clean($raw);
        if ($clean === '') {
            return $raw; // blank stays blank (nullable); punctuation-only input still fails the rule
        }

        try {
            $phone = new PhoneNumber($clean, $region);
            if ($phone->isValid()) {
                return $phone->formatE164();
            }
        } catch (Throwable) {
            // Unparseable — fall through and let the validator reject it.
        }

        return $clean;
    }

    /** E.164 for a valid number, null otherwise (for callers that must not store garbage). */
    public static function toE164(?string $raw, string $region = self::DEFAULT_REGION): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $normalized = self::normalize($raw, $region);

        return is_string($normalized) && preg_match('/^\+[1-9]\d{6,14}$/', $normalized) === 1 && self::isValid($normalized, $region)
            ? $normalized
            : null;
    }

    public static function isValid(string $value, string $region = self::DEFAULT_REGION): bool
    {
        try {
            return (new PhoneNumber($value, $region))->isValid();
        } catch (Throwable) {
            return false;
        }
    }

    /** Strips formatting, converts Eastern digits, and expands the common Saudi short forms. */
    private static function clean(string $raw): string
    {
        $value = strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '＋' => '+',
        ]);

        // Whitespace (incl. NBSP), dashes, dots, slashes, parentheses and bidi/zero-width marks.
        $value = (string) preg_replace('/[\s\x{00A0}\x{2000}-\x{200F}\x{2010}-\x{2015}\x{202A}-\x{202E}\x{2060}\x{FEFF}\-.\/()]+/u', '', $value);

        if (str_starts_with($value, '00')) {
            return '+'.substr($value, 2);
        }
        if (preg_match('/^9665\d{8}$/', $value) === 1) {
            return '+'.$value;
        }
        if (preg_match('/^05\d{8}$/', $value) === 1) {
            return '+966'.substr($value, 1);
        }
        if (preg_match('/^5\d{8}$/', $value) === 1) {
            return '+966'.$value;
        }

        return $value;
    }
}
