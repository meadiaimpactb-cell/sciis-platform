<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCmsContent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** CMS-02 — public content managed by staff. */
class Faq extends Model
{
    use HasFactory, IsCmsContent;

    protected $table = 'faqs';

    protected $fillable = [
        'slug', 'title_ar', 'title_en', 'body_ar', 'body_en', 'version', 'is_published', 'published_at', 'sort',
        'category', 'meta', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'sort' => 'integer',
            'meta' => 'array',
        ];
    }
}
