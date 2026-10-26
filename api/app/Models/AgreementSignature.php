<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgreementParty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * AGR-05 — signature evidence (like consents, PRV-02): signer, party, timestamp, IP, user agent, the provider used and the
 * SHA-256 of the exact version text. Inserted once, never updated or deleted.
 */
class AgreementSignature extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agreement_id', 'version_id', 'user_id', 'party', 'signer_name', 'signer_email', 'provider', 'provider_reference',
        'text_hash', 'consent_text', 'locale', 'ip', 'user_agent', 'signed_at',
    ];

    protected function casts(): array
    {
        return ['party' => AgreementParty::class, 'signed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Agreement signatures are immutable (AGR-05).'));
        static::deleting(fn (): never => throw new LogicException('Agreement signatures are immutable (AGR-05).'));
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AgreementVersion::class, 'version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
