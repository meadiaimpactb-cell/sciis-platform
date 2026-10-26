<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journey 11.10 — a periodic (monthly / quarterly / annual) KPI report: generated as a draft from the live KPIs,
 * approved by a holder of `reports.approve`, then published to its audience (all staff, or also the public API).
 */
class PeriodicReport extends Model
{
    use HasUuid;

    public const PERIODS = ['monthly', 'quarterly', 'annual'];

    public const AUDIENCES = ['staff', 'public'];

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PUBLISHED = 'published';

    protected $fillable = [
        'period_type', 'period_start', 'period_end', 'title', 'summary', 'snapshot', 'status', 'audience', 'review_note',
        'generated_by', 'generated_at', 'approved_by', 'approved_at', 'published_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'snapshot' => 'array',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }
}
