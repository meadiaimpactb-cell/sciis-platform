<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OpportunityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only transition log (ADM-01). */
class OpportunityStatusTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['opportunity_id', 'from_status', 'to_status', 'actor_user_id', 'reason', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return [
            'from_status' => OpportunityStatus::class,
            'to_status' => OpportunityStatus::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Status transitions are append-only.'));
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
