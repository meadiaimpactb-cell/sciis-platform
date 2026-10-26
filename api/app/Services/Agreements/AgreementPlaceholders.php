<?php

declare(strict_types=1);

namespace App\Services\Agreements;

use App\Models\Agreement;
use App\Models\Interest;

/**
 * AGR-01/02 — template placeholders: `{investor_name}` (or `{{investor_name}}`). The keys in AUTO are filled from the
 * connection; any other key found in a template is a manual field the staff fill when drafting.
 */
final class AgreementPlaceholders
{
    /** Filled automatically from the connection (staff may still override them). */
    public const AUTO = ['agreement_no', 'date', 'project_name', 'reference_no', 'owner_name', 'organization_name', 'investor_name', 'amount'];

    private const PATTERN = '/\{\{?\s*([a-z][a-z0-9_]{0,59})\s*\}?\}/i';

    /** Keys used in the given texts, in order of first appearance. @return list<string> */
    public static function keys(?string ...$texts): array
    {
        $keys = [];
        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }
            preg_match_all(self::PATTERN, $text, $m);
            foreach ($m[1] as $key) {
                $keys[strtolower($key)] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Replaces every placeholder that has a non-empty value; unknown or empty ones stay visible in the text so the
     * reviewer sees what is still missing.
     *
     * @param  array<string, string|null>  $values
     */
    public static function fill(?string $text, array $values): ?string
    {
        if ($text === null) {
            return null;
        }

        return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($values): string {
            $value = trim((string) ($values[strtolower($m[1])] ?? ''));

            return $value === '' ? $m[0] : $value;
        }, $text);
    }

    /** Placeholders still present in the text. @return list<string> */
    public static function unfilled(?string ...$texts): array
    {
        return self::keys(...$texts);
    }

    /**
     * Default values for a connection (Latin digits only). `agreement_no` needs the saved agreement.
     *
     * @return array<string, string>
     */
    public function defaults(Interest $interest, ?Agreement $agreement = null): array
    {
        $interest->loadMissing(['opportunity.owner.organization', 'opportunity.organization', 'investor.organization']);
        $opportunity = $interest->opportunity;
        $owner = $opportunity?->owner;
        $investor = $interest->investor;

        $amount = $opportunity?->funding_amount;

        return [
            'agreement_no' => $agreement?->number() ?? '',
            'date' => now()->format('Y-m-d'),
            'project_name' => (string) ($opportunity?->name ?? ''),
            'reference_no' => (string) ($opportunity?->reference_no ?? ''),
            'owner_name' => (string) ($owner?->name ?? ''),
            'organization_name' => (string) ($opportunity?->organization?->name ?? $owner?->organization?->name ?? $owner?->name ?? ''),
            'investor_name' => (string) ($investor?->organization?->name ?? $investor?->name ?? ''),
            'amount' => $amount !== null ? number_format((float) $amount, 0, '.', ',') : '',
        ];
    }
}
