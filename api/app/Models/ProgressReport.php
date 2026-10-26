<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\ProgressReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** PRJ-02 — periodic progress report (unified template + evidence), reviewed by staff. */
class ProgressReport extends Model
{
    protected $fillable = [
        'opportunity_id', 'period_start', 'period_end', 'due_on', 'narrative', 'achieved', 'delayed', 'risks', 'support_needed',
        'spend_amount', 'milestone_updates', 'execution_status', 'status', 'submitted_at', 'submitted_by',
        'review_note', 'reviewed_by', 'reviewed_at',
    ];

    protected $attributes = ['status' => 'submitted'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_on' => 'date',
            'spend_amount' => 'decimal:2',
            'milestone_updates' => 'array',
            'execution_status' => ExecutionStatus::class,
            'status' => ProgressReportStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
