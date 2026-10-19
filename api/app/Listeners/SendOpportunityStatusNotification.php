<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OpportunityStatusChanged;
use App\Notifications\OpportunityStatusChangedNotification;
use App\Services\NotificationService;

/** NTF-01 — owner notification on every transition. */
final class SendOpportunityStatusNotification
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(OpportunityStatusChanged $event): void
    {
        $owner = $event->opportunity->owner;
        $this->notifications->send($owner, new OpportunityStatusChangedNotification($event->opportunity, $event->from, $event->to, $event->reason));
    }
}
