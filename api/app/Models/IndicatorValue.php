<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IndicatorValueStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** IMP-02 — a measured value of an impact indicator: entered → verified | needs_clarification (with a reason). */
class IndicatorValue extends Model
{
    protected $fillable = [
        'impact_indicator_id', 'period_start', 'period_end', 'value', 'evidence_document_id', 'status',
        'entered_by', 'verified_by', 'verified_at', 'note', 'review_note',
    ];

    protected $attributes = ['status' => 'entered'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'value' => 'decimal:2',
            'status' => IndicatorValueStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(ImpactIndicator::class, 'impact_indicator_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
