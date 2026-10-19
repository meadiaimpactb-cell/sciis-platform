<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum OpportunityStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case New = 'new';
    case UnderReview = 'under_review';
    case NeedsCompletion = 'needs_completion';
    case NotEligible = 'not_eligible';
    case Eligible = 'eligible';
    case Connected = 'connected';
    case Negotiating = 'negotiating';
    case InExecution = 'in_execution';
    case Operating = 'operating';
    case Withdrawn = 'withdrawn';
    case Closed = 'closed';

    /**
     * Statuses that are never visible to investors (SPEC §3 visibility, doc §6.6).
     *
     * @return array<int, self>
     */
    public static function hiddenFromInvestors(): array
    {
        return [self::Draft, self::New, self::UnderReview, self::NeedsCompletion, self::NotEligible];
    }

    /** @return array<int, self> */
    public static function connectedOrLater(): array
    {
        return [self::Connected, self::Negotiating, self::InExecution, self::Operating];
    }

    /** @return array<int, self> */
    public static function terminal(): array
    {
        return [self::Withdrawn, self::Closed];
    }

    public function isHiddenFromInvestors(): bool
    {
        return $this->in(self::hiddenFromInvestors());
    }

    public function isConnectedOrLater(): bool
    {
        return $this->in(self::connectedOrLater());
    }

    public function isTerminal(): bool
    {
        return $this->in(self::terminal());
    }

    /** Owner may still edit the submission sections in these states (ENT-05). */
    public function isEditableByOwner(): bool
    {
        return $this->in([self::Draft, self::NeedsCompletion]);
    }

    /** Owner-initiated withdrawal is allowed before connection (ENT-07). */
    public function allowsWithdrawal(): bool
    {
        return $this->in([self::Draft, self::New, self::UnderReview, self::NeedsCompletion, self::NotEligible, self::Eligible]);
    }

    /** Brand colour token for the status (SPEC §10). */
    public function color(): string
    {
        return match ($this) {
            self::New => '#1F5BFF',
            self::UnderReview => '#B45309',
            self::NeedsCompletion => '#D97706',
            self::NotEligible => '#B91C1C',
            self::Eligible => '#047857',
            self::Connected => '#0F766E',
            self::Negotiating => '#6D28D9',
            self::InExecution => '#1D4ED8',
            self::Operating => '#065F46',
            self::Withdrawn => '#6B7280',
            self::Closed => '#374151',
            self::Draft => '#94A3B8',
        };
    }
}
