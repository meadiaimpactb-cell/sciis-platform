<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum MembershipStatus: string
{
    use HasLabel;

    case Registered = 'registered';
    case UnderReview = 'under_review';
    case NeedsCompletion = 'needs_completion';
    case Approved = 'approved';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    /**
     * Membership state machine (SPEC §3):
     * registered → under_review → (needs_completion ⇄ under_review) → approved | rejected; approved → suspended → approved.
     *
     * @return array<string, array<int, self>>
     */
    public static function transitions(): array
    {
        return [
            self::Registered->value => [self::UnderReview],
            self::UnderReview->value => [self::NeedsCompletion, self::Approved, self::Rejected],
            self::NeedsCompletion->value => [self::UnderReview],
            self::Approved->value => [self::Suspended],
            self::Suspended->value => [self::Approved],
            self::Rejected->value => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, self::transitions()[$this->value] ?? [], true);
    }
}
