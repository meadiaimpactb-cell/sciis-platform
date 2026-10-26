<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OpportunityStatus;
use App\Events\OpportunityStatusChanged;
use App\Services\WorkloadAssignment;

/** ADM-09 — a submission (→ new) gets a reviewer automatically when `assignment.auto_assign_reviewer` is on. */
class AutoAssignReviewer
{
    public function __construct(private readonly WorkloadAssignment $assignment) {}

    public function handle(OpportunityStatusChanged $event): void
    {
        if ($event->to !== OpportunityStatus::New) {
            return;
        }

        $this->assignment->autoAssign($event->opportunity->refresh());
    }
}
