<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MilestoneStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** PRJ-01 — a milestone of the project plan (deliverable, due date, responsibility, budget share, completion evidence). */
class Milestone extends Model
{
    protected $fillable = [
        'opportunity_id', 'title', 'description', 'deliverable', 'responsible', 'budget_share', 'sort', 'due_at',
        'completed_at', 'completed_by', 'evidence_note', 'status',
    ];

    protected $attributes = ['status' => 'planned', 'sort' => 0];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'budget_share' => 'decimal:2',
            'sort' => 'integer',
            'status' => MilestoneStatus::class,
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** Completion evidence (DocumentService, kind progress_evidence). */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isDone(): bool
    {
        return $this->status === MilestoneStatus::Done;
    }

    /** Not done and the due date has passed. */
    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_at !== null && $this->due_at->copy()->endOfDay()->isPast();
    }

    /** Stored status, shown as `delayed` once overdue. */
    public function displayStatus(): MilestoneStatus
    {
        return $this->isOverdue() ? MilestoneStatus::Delayed : $this->status;
    }
}
