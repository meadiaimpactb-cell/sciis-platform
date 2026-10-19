<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCmsContent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** CMS-02 — public content managed by staff. */
class Event extends Model
{
    use HasFactory, IsCmsContent;

    protected $table = 'events';

    protected $fillable = [
        'slug', 'title_ar', 'title_en', 'body_ar', 'body_en', 'version', 'is_published', 'published_at', 'sort',
        'starts_at', 'ends_at', 'location', 'registration_url', 'meta', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'sort' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
