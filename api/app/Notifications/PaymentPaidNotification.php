<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Payment receipt (membership/service/training/sponsorship only). */
class PaymentPaidNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(private readonly Payment $payment)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'payment';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.payment.subject'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.payment.line', ['amount' => number_format((float) $this->payment->amount, 2), 'currency' => $this->payment->currency, 'purpose' => $this->payment->purpose->label()]))
            ->line(__('notifications.payment.reference', ['ref' => $this->payment->uuid]));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.payment.sms', ['amount' => number_format((float) $this->payment->amount, 2), 'currency' => $this->payment->currency]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment.paid',
            'title' => __('notifications.payment.subject'),
            'body' => __('notifications.payment.line', ['amount' => number_format((float) $this->payment->amount, 2), 'currency' => $this->payment->currency, 'purpose' => $this->payment->purpose->label()]),
            'payment_uuid' => $this->payment->uuid,
            'amount' => (float) $this->payment->amount,
            'currency' => $this->payment->currency,
            'purpose' => $this->payment->purpose->value,
        ];
    }
}
