<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvaluationResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationDecision extends Model
{
    protected $fillable = ['opportunity_id', 'result', 'conditions', 'reasons', 'decided_by', 'decided_at', 'advisory_referral_id'];

    protected function casts(): array
    {
        return [
            'result' => EvaluationResult::class,
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
}
