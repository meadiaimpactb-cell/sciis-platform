<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * NTF-04 — a WhatsApp business-initiated message: a Meta-approved template (name + language) and its body
 * parameters in order ({{1}}, {{2}} …). `text` is the readable rendering (log driver, audit, fallback).
 */
final class WhatsAppMessage
{
    /** @param list<string> $parameters */
    public function __construct(
        public readonly string $template,
        public readonly string $language,
        public readonly array $parameters,
        public readonly string $text,
    ) {}

    /**
     * Builds the message of a notification key (`sciis.whatsapp.templates.{key}`) in the recipient's locale.
     *
     * @param  list<string|int|null>  $parameters
     */
    public static function forKey(string $key, string $locale, array $parameters, string $text): ?self
    {
        $template = (string) config("sciis.whatsapp.templates.{$key}", '');
        if ($template === '') {
            return null; // no approved template configured for this alert
        }

        $languages = (array) config('sciis.whatsapp.languages', []);
        $language = (string) ($languages[$locale] ?? $languages['ar'] ?? 'ar');

        // Template parameters cannot be empty, contain new lines / tabs or more than 4 consecutive spaces (Meta rules).
        $clean = array_map(static function (string|int|null $p): string {
            $value = trim(preg_replace('/\s+/u', ' ', (string) $p) ?? '');

            return $value === '' ? '-' : mb_substr($value, 0, 1024);
        }, $parameters);

        return new self($template, $language, array_values($clean), $text);
    }
}
