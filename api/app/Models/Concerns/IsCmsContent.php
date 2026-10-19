<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/** Shared behaviour for pages / faqs / news_posts / events (CMS-02). */
trait IsCmsContent
{
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->where(function (Builder $q): void {
            $q->whereNull('published_at')->orWhere('published_at', '<=', now());
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function title(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $en = $this->getAttribute('title_en');

        return ($locale === 'en' && is_string($en) && $en !== '') ? $en : (string) $this->getAttribute('title_ar');
    }

    public function body(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $en = $this->getAttribute('body_en');

        return ($locale === 'en' && is_string($en) && $en !== '') ? $en : $this->getAttribute('body_ar');
    }
}
