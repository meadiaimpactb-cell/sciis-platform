<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * PRV-02: consents are immutable — inserted once with version + text hash, never updated.
 */
class Consent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'type', 'version', 'text_hash', 'text_snapshot', 'locale', 'accepted_at', 'ip', 'user_agent', 'subject_type', 'subject_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Consents are immutable (PRV-02).'));
        static::deleting(fn (): never => throw new LogicException('Consents are immutable (PRV-02).'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
