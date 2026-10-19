<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PaymentPaid;
use App\Notifications\PaymentPaidNotification;
use App\Services\NotificationService;

final class SendPaymentPaidNotification
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(PaymentPaid $event): void
    {
        $this->notifications->send($event->payment->user, new PaymentPaidNotification($event->payment));
    }
}
