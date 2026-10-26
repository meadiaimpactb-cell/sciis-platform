<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** EVL-04 — temporary read access for an advisory member: a question on one file, answered with an opinion until expiry. */
class AdvisoryReferral extends Model
{
    protected $fillable = ['opportunity_id', 'advisory_user_id', 'referred_by', 'question', 'expires_at', 'opinion', 'opined_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'opined_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisory_user_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /** Referrals that still grant access (no expiry or expiry in the future). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
