<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired by OpportunityStateMachine after every successful transition (ADM-01, NTF-01). Dispatched after the DB commit. */
final class OpportunityStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Opportunity $opportunity,
        public readonly ?OpportunityStatus $from,
        public readonly OpportunityStatus $to,
        public readonly ?User $actor,
        public readonly ?string $reason = null,
    ) {}
}
