<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvaluationResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    protected $fillable = [
        'assignment_id', 'criteria_version', 'criteria_snapshot', 'scores', 'axis_totals', 'overall',
        'recommendation', 'comment', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'criteria_version' => 'integer',
            'criteria_snapshot' => 'array',
            'scores' => 'array',
            'axis_totals' => 'array',
            'overall' => 'decimal:2',
            'recommendation' => EvaluationResult::class,
            'submitted_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EvaluationAssignment::class, 'assignment_id');
    }
}
