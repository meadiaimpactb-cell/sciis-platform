<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * CMS-05 — contact form follow-up.
 *  - Sender (on-demand mail notifiable): confirmation e-mail carrying the reference number.
 *  - Assigned staff user (database): "a new message was routed to you".
 */
class ContactReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ContactMessage $message)
    {
        $this->onQueue('notifications');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.contact_received.subject', ['ref' => $this->message->reference_no]))
            ->greeting(__('notifications.greeting', ['name' => $this->message->name]))
            ->line(__('notifications.contact_received.line'))
            ->line(__('notifications.contact_received.reference', ['ref' => $this->message->reference_no]))
            ->line(__('notifications.contact_received.category', ['category' => $this->message->category->label()]))
            ->line(__('notifications.contact_received.footer'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'contact.assigned',
            'title' => __('notifications.contact_received.staff_title'),
            'body' => __('notifications.contact_received.staff_line', ['ref' => $this->message->reference_no, 'category' => $this->message->category->label()]),
            'contact_message_id' => $this->message->id,
            'reference_no' => $this->message->reference_no,
            'category' => $this->message->category->value,
        ];
    }
}
