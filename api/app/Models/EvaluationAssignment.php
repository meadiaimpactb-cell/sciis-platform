<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** EVL-02 — exactly two evaluators per opportunity; COI gate before scoring. */
class EvaluationAssignment extends Model
{
    protected $fillable = ['opportunity_id', 'evaluator_user_id', 'assigned_by', 'status', 'coi_declared_at', 'coi_has_conflict', 'due_at'];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'coi_declared_at' => 'datetime',
            'coi_has_conflict' => 'boolean',
            'due_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(Evaluation::class, 'assignment_id');
    }

    public function canScore(): bool
    {
        return $this->coi_declared_at !== null
            && $this->coi_has_conflict === false
            && in_array($this->status, [AssignmentStatus::Assigned, AssignmentStatus::CoiDeclared], true);
    }
}
