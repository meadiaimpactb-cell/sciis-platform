<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IdentityVerificationStatus;
use App\Models\Concerns\HasUuid;
use App\Services\Identity\IdentityRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ACC-09 — one national identity verification request (start → poll → result). The national ID is kept
 * encrypted only while the request is pending; afterwards only its keyed hash remains.
 */
class IdentityVerification extends Model
{
    use HasUuid;

    protected $fillable = ['user_id', 'driver', 'national_id_hash', 'national_id_encrypted', 'transaction_id', 'random', 'status', 'expires_at', 'completed_at'];

    protected $hidden = ['national_id_hash', 'national_id_encrypted'];

    protected function casts(): array
    {
        return [
            'status' => IdentityVerificationStatus::class,
            'national_id_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toRequest(): IdentityRequest
    {
        return new IdentityRequest((string) $this->transaction_id, $this->random, CarbonImmutable::parse($this->expires_at ?? now()));
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'random' => $this->status === IdentityVerificationStatus::Pending ? $this->random : null,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
