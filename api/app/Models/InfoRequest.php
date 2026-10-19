<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InfoRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** OPP-05 / MSG-01 — mediated Q&A; the investor identity is never forwarded to the owner. */
class InfoRequest extends Model
{
    protected $fillable = [
        'opportunity_id', 'investor_user_id', 'subject', 'body', 'status', 'handled_by', 'forwarded_to_owner_at',
        'owner_answer', 'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InfoRequestStatus::class,
            'forwarded_to_owner_at' => 'datetime',
            'answered_at' => 'datetime',
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

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
