<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExecutionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** PRJ / IMP (P2) */
class ProgressReport extends Model
{
    protected $fillable = [
        'opportunity_id', 'period_start', 'period_end', 'achieved', 'delayed', 'risks', 'support_needed',
        'execution_status', 'submitted_at', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'execution_status' => ExecutionStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
