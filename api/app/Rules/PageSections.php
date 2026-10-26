<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CMS — `meta.sections` of a public page: { sectionKey: { ar: value, en: value } } where value is
 * null, a string (≤ 2000 chars), a list of strings, or a list of flat objects (string / bool / number fields).
 * Lists hold at most 20 items; every string is ≤ 2000 chars.
 */
class PageSections implements ValidationRule
{
    public const MAX_SECTIONS = 60;

    public const MAX_ITEMS = 20;

    public const MAX_FIELDS = 10;

    public const MAX_STRING = 2000;

    private const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $fail(__('validation.array', ['attribute' => $attribute]));

            return;
        }
        if (count($value) > self::MAX_SECTIONS) {
            $fail(__('validation.max.array', ['attribute' => $attribute, 'max' => self::MAX_SECTIONS]));

            return;
        }

        foreach ($value as $key => $section) {
            $path = "{$attribute}.{$key}";
            if (! is_string($key) || ! preg_match(self::KEY, $key)) {
                $fail(__('validation.regex', ['attribute' => $path]));

                return;
            }
            if (! is_array($section) || array_diff(array_keys($section), ['ar', 'en']) !== []) {
                $fail(__('validation.array', ['attribute' => $path]));

                return;
            }
            foreach ($section as $locale => $localized) {
                $error = $this->checkValue($localized);
                if ($error !== null) {
                    $fail("{$path}.{$locale}: {$error}");

                    return;
                }
            }
        }
    }

    private function checkValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $this->checkString($value);
        }
        if (! is_array($value) || ! array_is_list($value)) {
            return __('validation.string', ['attribute' => 'value']);
        }
        if (count($value) > self::MAX_ITEMS) {
            return __('validation.max.array', ['attribute' => 'list', 'max' => self::MAX_ITEMS]);
        }

        foreach ($value as $item) {
            if (is_string($item)) {
                if (($e = $this->checkString($item)) !== null) {
                    return $e;
                }

                continue;
            }
            if (! is_array($item) || $item === [] || array_is_list($item) || count($item) > self::MAX_FIELDS) {
                return __('validation.array', ['attribute' => 'item']);
            }
            foreach ($item as $field => $fieldValue) {
                if (! is_string($field) || ! preg_match(self::KEY, $field)) {
                    return __('validation.regex', ['attribute' => 'field']);
                }
                if (is_string($fieldValue)) {
                    if (($e = $this->checkString($fieldValue)) !== null) {
                        return $e;
                    }
                } elseif (! is_bool($fieldValue) && ! is_int($fieldValue) && ! is_float($fieldValue) && $fieldValue !== null) {
                    return __('validation.string', ['attribute' => $field]);
                }
            }
        }

        return null;
    }

    private function checkString(string $value): ?string
    {
        return mb_strlen($value) > self::MAX_STRING
            ? __('validation.max.string', ['attribute' => 'text', 'max' => self::MAX_STRING])
            : null;
    }
}
