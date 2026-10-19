<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NominationResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MAT-01..03 — nominations with transparent match score and rationale. */
class Nomination extends Model
{
    protected $fillable = [
        'opportunity_id', 'investor_user_id', 'nominated_by', 'matched_criteria', 'match_score', 'rationale',
        'teaser_version', 'sent_at', 'response', 'decline_reason_id', 'decline_note', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'matched_criteria' => 'array',
            'match_score' => 'integer',
            'teaser_version' => 'integer',
            'sent_at' => 'datetime',
            'response' => NominationResponse::class,
            'responded_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investor_user_id');
    }

    public function nominator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominated_by');
    }

    public function declineReason(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'decline_reason_id');
    }
}
