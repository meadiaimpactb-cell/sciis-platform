<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InfoRequestStatus;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** OPP-05 / MSG-01 — mediated Q&A; the investor identity is never forwarded to the owner. */
class InfoRequest extends Model
{
    protected $fillable = [
        'opportunity_id', 'investor_user_id', 'subject', 'body', 'status', 'handled_by', 'forwarded_to_owner_at',
        'owner_answer', 'answered_at', 'closed_at', 'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InfoRequestStatus::class,
            'forwarded_to_owner_at' => 'datetime',
            'answered_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === InfoRequestStatus::Closed;
    }

    /** §6.3 — closes the request (staff at any stage, the investor once answered); callers check who may. */
    public function close(User $actor): self
    {
        $before = ['status' => $this->status->value];
        $this->fill(['status' => InfoRequestStatus::Closed, 'closed_at' => now(), 'closed_by' => $actor->id])->save();
        app(AuditLogger::class)->log('info_request.closed', $this, $before, ['status' => InfoRequestStatus::Closed->value], $actor);

        return $this;
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
