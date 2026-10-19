<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Shared behaviour for string-backed enums: translated labels and helper lists.
 * Labels live in lang/{ar,en}/enums.php keyed by the enum's short name.
 */
trait HasLabel
{
    public function label(): string
    {
        $group = static::translationGroup();
        $key = "enums.{$group}.{$this->value}";
        $label = __($key);

        return $label === $key ? $this->value : $label;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    public static function translationGroup(): string
    {
        $short = substr(strrchr(static::class, '\\') ?: static::class, 1);

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short) ?? $short);
    }

    public function is(self $other): bool
    {
        return $this === $other;
    }

    /** @param array<int, self> $cases */
    public function in(array $cases): bool
    {
        return in_array($this, $cases, true);
    }
}
