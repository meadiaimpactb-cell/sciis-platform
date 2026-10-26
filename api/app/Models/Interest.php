<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InterestStatus;
use App\Enums\OwnerDecision;
use Database\Factories\InterestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** OPP-05 / ENT-06 — dual-consent interest flow. */
class Interest extends Model
{
    /** @use HasFactory<InterestFactory> */
    use HasFactory;

    protected $fillable = [
        'opportunity_id', 'investor_user_id', 'nomination_id', 'status', 'owner_decision', 'owner_decided_at',
        'owner_decision_due_at', 'decline_reason_id', 'note', 'closed_reason', 'closed_at',
    ];

    private ?FileAccessGrant $resolvedGrant = null;

    private bool $grantResolved = false;

    protected function casts(): array
    {
        return [
            'status' => InterestStatus::class,
            'owner_decision' => OwnerDecision::class,
            'owner_decided_at' => 'datetime',
            'owner_decision_due_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): InterestFactory
    {
        return InterestFactory::new();
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investor_user_id');
    }

    public function nomination(): BelongsTo
    {
        return $this->belongsTo(Nomination::class);
    }

    public function declineReason(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'decline_reason_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', InterestStatus::Closed->value);
    }

    /** ENT-06 — the investor's full-file grant on this opportunity (one row per pair; memoised per instance). */
    public function accessGrant(): ?FileAccessGrant
    {
        if (! $this->grantResolved) {
            $this->resolvedGrant = FileAccessGrant::query()
                ->where('opportunity_id', $this->opportunity_id)
                ->where('investor_user_id', $this->investor_user_id)
                ->latest('id')
                ->first();
            $this->grantResolved = true;
        }

        return $this->resolvedGrant;
    }

    /** ENT-06 — the owner approved sharing and has not revoked it (the NDA may still be pending). */
    public function hasOwnerGrant(): bool
    {
        $grant = $this->accessGrant();

        return $grant !== null && $grant->granted_by_owner_at !== null && $grant->revoked_at === null;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
