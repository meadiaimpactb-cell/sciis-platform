<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ACC-08 — invitation of a colleague to an organisation account. Only the sha256 of the e-mailed token is stored.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property ?string $name
 * @property bool $can_act
 * @property Carbon $expires_at
 * @property ?Carbon $accepted_at
 * @property ?Carbon $revoked_at
 */
class OrganizationInvitation extends Model
{
    use HasUuid;

    protected $fillable = [
        'organization_id', 'email', 'name', 'can_act', 'token_hash', 'invited_by', 'expires_at',
        'accepted_at', 'accepted_user_id', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'can_act' => 'boolean',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Admin/representative endpoints reference invitations by id (scoped to the caller's organisation). */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Not accepted and not revoked (it may still be expired). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && ! $this->isExpired();
    }
}
