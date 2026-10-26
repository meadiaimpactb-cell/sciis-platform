<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * AGR-02..05 — draft → legal_review (in review) → changes_requested → approved → sent_for_signature → signed → archived,
 * or cancelled from any open state. Editing an approved / sent agreement creates a new version back in draft.
 */
enum AgreementStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case LegalReview = 'legal_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case SentForSignature = 'sent_for_signature';
    case Signed = 'signed';
    case Archived = 'archived';
    case Cancelled = 'cancelled';

    /** @return array<string, array<int, self>> */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::LegalReview, self::Cancelled],
            self::LegalReview->value => [self::Approved, self::ChangesRequested, self::Cancelled],
            self::ChangesRequested->value => [self::LegalReview, self::Cancelled],
            self::Approved->value => [self::SentForSignature, self::Signed, self::Draft, self::Cancelled],
            self::SentForSignature->value => [self::Signed, self::Draft, self::Cancelled],
            self::Signed->value => [self::Archived],
            self::Archived->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, self::transitions()[$this->value] ?? [], true);
    }

    /** A new text version may be proposed (AGR-02); from approved / sent it returns the agreement to draft. */
    public function isEditable(): bool
    {
        return $this->in([self::Draft, self::ChangesRequested, self::Approved, self::SentForSignature]);
    }

    /** Signed, archived and cancelled agreements are read-only (AGR-04). */
    public function isLocked(): bool
    {
        return $this->in([self::Signed, self::Archived, self::Cancelled]);
    }

    /** Still counts as the connection's agreement (one open or concluded agreement per connection). */
    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }
}
