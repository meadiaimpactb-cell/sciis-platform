<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'status', 'submitted_at', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at',
        'decision_note', 'terms_accepted_at', 'suspended_reason', 'completion_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'extra_answers' => 'array', // FRM-01 — membership review answers {key, version, answers, answered_at}
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** ADM-08 — staff member the membership file is assigned to (set from the queue, one or many at once). */
    public function assignedReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }

    public function isApproved(): bool
    {
        return $this->status === MembershipStatus::Approved;
    }
}
