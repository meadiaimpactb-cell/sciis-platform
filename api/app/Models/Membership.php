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

    public function isApproved(): bool
    {
        return $this->status === MembershipStatus::Approved;
    }
}
