<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * AUD-01/02 — append-only. The DB trigger `audit_logs_immutable` blocks UPDATE/DELETE on Postgres,
 * and this model refuses them at the ORM level too so nothing ever rewrites history.
 */
final class AuditLog extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'action', 'auditable_type', 'auditable_id', 'before', 'after', 'ip', 'user_agent', 'request_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('audit_logs is append-only (AUD-02).'));
        static::deleting(fn (): never => throw new LogicException('audit_logs is append-only (AUD-02).'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('audit_logs is append-only (AUD-02).');
    }

    public function delete(): ?bool
    {
        throw new LogicException('audit_logs is append-only (AUD-02).');
    }
}
