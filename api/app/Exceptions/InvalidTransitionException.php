<?php

declare(strict_types=1);

namespace App\Exceptions;

class InvalidTransitionException extends DomainException
{
    public static function forStatuses(string $from, string $to): self
    {
        return new self(__('messages.transitions.not_allowed', ['from' => $from, 'to' => $to]), 'invalid_transition', 422);
    }

    public static function guardFailed(string $reasonKey, array $params = []): self
    {
        return new self(__($reasonKey, $params), 'transition_guard_failed', 422);
    }
}
