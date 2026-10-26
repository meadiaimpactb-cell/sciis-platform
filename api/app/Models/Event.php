<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCmsContent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** CMS-02 — public content managed by staff. */
class Event extends Model
{
    use HasFactory, IsCmsContent;

    protected $table = 'events';

    protected $fillable = [
        'slug', 'title_ar', 'title_en', 'body_ar', 'body_en', 'version', 'is_published', 'published_at', 'sort',
        'starts_at', 'ends_at', 'location', 'registration_url', 'meta', 'created_by', 'updated_by',
        // MTG-04 — on-platform registration.
        'registration_enabled', 'registration_capacity', 'registration_deadline', 'registration_closed_at',
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
            'registration_enabled' => 'boolean',
            'registration_capacity' => 'integer',
            'registration_deadline' => 'datetime',
            'registration_closed_at' => 'datetime',
        ];
    }

    /** MTG-04 */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }
}
