<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvaluationResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EVL-03 — the supervisor's final decision. `improvement_plan` = `[{text, due_at: Y-m-d|null}]`, entered with
 * "not eligible now" (and optionally with conditions) and shown to the owner.
 */
class EvaluationDecision extends Model
{
    protected $fillable = ['opportunity_id', 'result', 'conditions', 'reasons', 'improvement_plan', 'decided_by', 'decided_at', 'advisory_referral_id'];

    protected function casts(): array
    {
        return [
            'result' => EvaluationResult::class,
            'improvement_plan' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function advisoryReferral(): BelongsTo
    {
        return $this->belongsTo(AdvisoryReferral::class);
    }

    /**
     * What the owner sees of the decision (no decider identity).
     *
     * @return array{result: string, result_label: string, conditions: ?string, reasons: ?string, improvement_plan: array<int, array{text: string, due_at: ?string}>, decided_at: ?string}
     */
    public function toOwnerArray(): array
    {
        return [
            'result' => $this->result->value,
            'result_label' => $this->result->label(),
            'conditions' => $this->conditions,
            'reasons' => $this->reasons,
            'improvement_plan' => array_values(array_map(
                fn (array $item): array => ['text' => (string) ($item['text'] ?? ''), 'due_at' => $item['due_at'] ?? null],
                array_filter((array) ($this->improvement_plan ?? []), 'is_array'),
            )),
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
