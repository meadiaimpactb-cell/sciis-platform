<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ENT-06 — full-file access = owner approval + NDA consent, revocable. */
class FileAccessGrant extends Model
{
    protected $fillable = ['opportunity_id', 'investor_user_id', 'granted_by_owner_at', 'nda_consent_id', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'granted_by_owner_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    public function ndaConsent(): BelongsTo
    {
        return $this->belongsTo(Consent::class, 'nda_consent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('granted_by_owner_at')->whereNotNull('nda_consent_id')->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->granted_by_owner_at !== null && $this->nda_consent_id !== null && $this->revoked_at === null;
    }
}
