<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgreementParty;
use App\Enums\AgreementReviewDecision;
use App\Enums\AgreementStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * AGR-02..05 — one agreement per connection (interest): versioned text, legal review, signature by both parties.
 * The council is NOT a party by default (doc §16.1).
 */
class Agreement extends Model
{
    use HasUuid;

    protected $fillable = [
        'opportunity_id', 'interest_id', 'investor_user_id', 'template_id', 'title', 'status', 'council_is_party', 'current_version_id',
        'signed_document_id', 'signature_method', 'signature_provider', 'created_by', 'review_requested_at', 'approved_at',
        'approved_by', 'sent_for_signature_at', 'signed_at', 'archived_at', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgreementStatus::class,
            'council_is_party' => 'boolean',
            'review_requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_for_signature_at' => 'datetime',
            'signed_at' => 'datetime',
            'archived_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function interest(): BelongsTo
    {
        return $this->belongsTo(Interest::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investor_user_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AgreementTemplate::class, 'template_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AgreementVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AgreementVersion::class, 'current_version_id');
    }

    public function reviews(): HasManyThrough
    {
        return $this->hasManyThrough(AgreementReview::class, AgreementVersion::class, 'agreement_id', 'version_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(AgreementSignature::class);
    }

    public function signedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'signed_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** AGR-04 — agreements a project owner or investor is a party to. */
    public function scopeForParty(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            $q->where('investor_user_id', $user->id)
                ->orWhereHas('opportunity', fn (Builder $o) => $o->where('owner_user_id', $user->id));
        });
    }

    /** AGR-04 — parties only see agreements with at least one legally approved version. */
    public function scopeVisibleToParties(Builder $query): Builder
    {
        return $query->whereHas('versions.reviews', fn (Builder $r) => $r->where('decision', AgreementReviewDecision::Approved->value));
    }

    /** Human reference shown on screens and the print view, e.g. AGR-2026-0007. */
    public function number(): string
    {
        return sprintf('AGR-%s-%04d', ($this->created_at ?? now())->format('Y'), $this->id);
    }

    public function partyFor(User $user): ?AgreementParty
    {
        $opportunity = $this->relationLoaded('opportunity') ? $this->opportunity : $this->opportunity()->first();

        return match (true) {
            $opportunity !== null && $opportunity->owner_user_id === $user->id => AgreementParty::Owner,
            $this->investor_user_id !== null && $this->investor_user_id === $user->id => AgreementParty::Investor,
            default => null,
        };
    }

    /** User expected to sign for a party. */
    public function partyUser(AgreementParty $party): ?User
    {
        return $party === AgreementParty::Owner ? $this->opportunity?->owner : $this->investor;
    }

    /** Signatures collected on the given (default: current) version, keyed by party. @return array<string, AgreementSignature> */
    public function signaturesOn(?AgreementVersion $version = null): array
    {
        $version ??= $this->currentVersion;
        if ($version === null) {
            return [];
        }

        $signatures = $this->relationLoaded('signatures') ? $this->signatures : $this->signatures()->get();

        return $signatures->where('version_id', $version->id)->keyBy(fn (AgreementSignature $s): string => $s->party->value)->all();
    }

    public function isFullySignedOn(AgreementVersion $version): bool
    {
        $signed = $this->signaturesOn($version);

        return isset($signed[AgreementParty::Owner->value], $signed[AgreementParty::Investor->value]);
    }
}
