<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADM-04 / ENT-04 — "المطلوب منك الآن". */
class CompletionRequest extends Model
{
    protected $fillable = ['opportunity_id', 'requested_by', 'due_at', 'items', 'resolved_at', 'reminders_sent'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            // Journey 11.3 — reminder thresholds (days before due) already sent to the owner.
            'reminders_sent' => 'array',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
