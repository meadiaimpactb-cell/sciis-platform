<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IndicatorValueStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorValue extends Model
{
    protected $fillable = [
        'impact_indicator_id', 'period_start', 'period_end', 'value', 'evidence_document_id', 'status',
        'entered_by', 'verified_by', 'verified_at', 'note',
    ];

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
}
