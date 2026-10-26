<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCmsContent;
use Illuminate\Database\Eloquent\Model;

/** Impact story (social proof) — CMS type `stories`; body_* is the summary, meta.cover / meta.logo the images. */
class Story extends Model
{
    use IsCmsContent;

    protected $table = 'stories';

    protected $fillable = [
        'slug', 'title_ar', 'title_en', 'body_ar', 'body_en', 'is_published', 'published_at', 'sort',
        'sector_ar', 'sector_en', 'beneficiaries', 'impact_metric_ar', 'impact_metric_en', 'link_url',
        'meta', 'created_by', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['is_published' => false, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'sort' => 'integer',
            'beneficiaries' => 'integer',
            'meta' => 'array',
        ];
    }

    public function localized(string $column, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $en = $this->getAttribute("{$column}_en");

        return ($locale === 'en' && is_string($en) && $en !== '') ? $en : $this->getAttribute("{$column}_ar");
    }
}
