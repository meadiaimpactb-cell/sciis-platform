<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/** Resolves label_ar / label_en according to the current application locale. */
trait HasTaxonomyLabel
{
    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $en = $this->getAttribute('label_en');

        return ($locale === 'en' && is_string($en) && $en !== '') ? $en : (string) $this->getAttribute('label_ar');
    }
}
