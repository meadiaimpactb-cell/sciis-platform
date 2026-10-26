<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OPP-08 — a named catalogue search (`kind = filter`, `filters` = App\Support\OpportunityFilters::normalize() shape);
 * OPP-07 — `notify` sends an alert when a newly listed opportunity matches. One `kind = profile` row per investor is
 * the "any new opportunity matching my profile" switch (no filters).
 */
class SavedFilter extends Model
{
    public const KIND_FILTER = 'filter';

    public const KIND_PROFILE = 'profile';

    /** Saved searches per investor. */
    public const MAX_PER_USER = 20;

    protected $fillable = ['user_id', 'kind', 'name', 'filters', 'notify'];

    protected $attributes = ['kind' => self::KIND_FILTER];

    protected function casts(): array
    {
        return ['filters' => 'array', 'notify' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeSearches(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_FILTER);
    }

    public function scopeProfileSwitch(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_PROFILE);
    }
}
