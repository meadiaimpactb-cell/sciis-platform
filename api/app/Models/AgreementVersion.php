<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgreementReviewDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * AGR-02 — every edit of an agreement is a new immutable version. `text_hash` is the SHA-256 of the exact text the
 * parties sign (AGR-05): the Arabic body, followed — when present — by a blank line and the English body.
 */
class AgreementVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['agreement_id', 'version', 'body', 'body_en', 'placeholders', 'text_hash', 'change_note', 'proposed_by', 'created_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'placeholders' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $version->text_hash = self::hashText((string) $version->body, $version->body_en);
        });
        // The text of a version never changes once written (signatures reference its hash).
        static::updating(function (self $version): void {
            if ($version->isDirty(['body', 'body_en', 'text_hash', 'version', 'agreement_id'])) {
                throw new LogicException('Agreement versions are immutable (AGR-02).');
            }
        });
    }

    public static function signingText(string $body, ?string $bodyEn): string
    {
        $en = trim((string) $bodyEn);

        return $en === '' ? $body : $body."\n\n".$en;
    }

    public static function hashText(string $body, ?string $bodyEn): string
    {
        return hash('sha256', self::signingText($body, $bodyEn));
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AgreementReview::class, 'version_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(AgreementSignature::class, 'version_id');
    }

    public function latestReview(): ?AgreementReview
    {
        $reviews = $this->relationLoaded('reviews') ? $this->reviews : $this->reviews()->get();

        return $reviews->sortByDesc('id')->first();
    }

    /** AGR-03 — the version's latest legal review approved it. */
    public function isApproved(): bool
    {
        return $this->latestReview()?->decision === AgreementReviewDecision::Approved->value;
    }
}
