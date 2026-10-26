<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\OpportunityStatusChanged;
use App\Events\PaymentPaid;
use App\Listeners\AutoAssignReviewer;
use App\Listeners\SendOpportunityStatusNotification;
use App\Listeners\SendPaymentPaidNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        OpportunityStatusChanged::class => [SendOpportunityStatusNotification::class, AutoAssignReviewer::class], // ADM-09
        PaymentPaid::class => [SendPaymentPaidNotification::class],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
