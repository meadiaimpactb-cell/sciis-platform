<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** EVL-04 (P2) — temporary read access for advisory members. */
class AdvisoryReferral extends Model
{
    protected $fillable = ['opportunity_id', 'advisory_user_id', 'question', 'expires_at', 'opinion', 'opined_at'];

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

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
