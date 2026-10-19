<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum InterestStatus: string
{
    use HasLabel;

    case Sent = 'sent';
    case AwaitingOwnerApproval = 'awaiting_owner_approval';
    case Shared = 'shared';
    case Connected = 'connected';
    case Meeting = 'meeting';
    case Closed = 'closed';

    /**
     * Interest state machine (SPEC §3): sent → awaiting_owner_approval → shared → connected → meeting → closed;
     * closed reachable from any state.
     *
     * @return array<string, array<int, self>>
     */
    public static function transitions(): array
    {
        return [
            self::Sent->value => [self::AwaitingOwnerApproval, self::Closed],
            self::AwaitingOwnerApproval->value => [self::Shared, self::Closed],
            self::Shared->value => [self::Connected, self::Closed],
            self::Connected->value => [self::Meeting, self::Closed],
            self::Meeting->value => [self::Connected, self::Closed],
            self::Closed->value => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, self::transitions()[$this->value] ?? [], true);
    }

    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }
}
