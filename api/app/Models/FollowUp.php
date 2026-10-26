<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\IndicatorFrequency;
use App\Enums\MilestonePlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRJ-01..03 — follow-up state of one project after connection (plan approval, execution status with optional
 * staff override + reason, remediation plan, escalation, reporting anchor and the reminder log).
 *
 * @property MilestonePlanStatus $plan_status
 * @property ?ExecutionStatus $computed_status
 * @property ?ExecutionStatus $status_override
 */
class FollowUp extends Model
{
    protected $fillable = [
        'opportunity_id', 'plan_status', 'plan_version', 'plan_submitted_at', 'plan_reviewed_at', 'plan_reviewed_by', 'plan_review_note',
        'computed_status', 'status_computed_at', 'status_override', 'override_reason', 'override_by', 'override_at',
        'remediation_plan', 'remediation_updated_at', 'escalated_at', 'execution_started_at', 'report_frequency', 'reminders_sent',
    ];

    protected $attributes = ['plan_status' => 'draft', 'plan_version' => 0];

    protected function casts(): array
    {
        return [
            'plan_status' => MilestonePlanStatus::class,
            'plan_version' => 'integer',
            'plan_submitted_at' => 'datetime',
            'plan_reviewed_at' => 'datetime',
            'computed_status' => ExecutionStatus::class,
            'status_computed_at' => 'datetime',
            'status_override' => ExecutionStatus::class,
            'override_at' => 'datetime',
            'remediation_updated_at' => 'datetime',
            'escalated_at' => 'datetime',
            'execution_started_at' => 'datetime',
            'report_frequency' => IndicatorFrequency::class,
            'reminders_sent' => 'array',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function planReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plan_reviewed_by');
    }

    public function overrideAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }

    /** PRJ-03 — the status everyone sees: the staff override when set, else the computed one. */
    public function effectiveStatus(): ?ExecutionStatus
    {
        return $this->status_override ?? $this->computed_status;
    }
}
