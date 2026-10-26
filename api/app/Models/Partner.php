<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCmsContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Partner (social proof) — CMS type `partners`; title_* is the partner name, meta.logo the logo.
 * Doc rule: partners appear only with an approved name — never anonymous logos — hence `approved_for_display`.
 */
class Partner extends Model
{
    use IsCmsContent;

    protected $table = 'partners';

    protected $fillable = [
        'slug', 'title_ar', 'title_en', 'body_ar', 'body_en', 'is_published', 'published_at', 'sort',
        'link_url', 'approved_for_display', 'meta', 'created_by', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['approved_for_display' => false, 'is_published' => false, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'sort' => 'integer',
            'approved_for_display' => 'boolean',
            'meta' => 'array',
        ];
    }

    /** Published + approved for display (what the public site may show). */
    public function scopeDisplayable(Builder $query): Builder
    {
        return $this->scopePublished($query)->where('approved_for_display', true);
    }
}
