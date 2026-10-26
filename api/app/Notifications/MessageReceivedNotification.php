<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ThreadMessage;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * MTG-03 — a new in-platform message on a record (in-app + e-mail per preferences, category `message`).
 * The e-mail never carries the message text: the recipient signs in to read it.
 */
class MessageReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    /** @param array<string, mixed> $subject ThreadSubject (type, key, title, reference_no, opportunity_uuid) */
    public function __construct(
        private readonly ThreadMessage $message,
        private readonly array $subject,
        private readonly bool $fromStaff,
    ) {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'message';
    }

    private function sender(): string
    {
        return $this->fromStaff ? __('notifications.message_received.council') : (string) ($this->message->author?->name ?? '');
    }

    private function path(object $notifiable): string
    {
        $isStaff = method_exists($notifiable, 'isStaff') && $notifiable->isStaff();

        return ($isStaff ? '/app/admin/messages' : '/app/messages').'?thread='.$this->message->thread?->uuid;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = (string) ($this->subject['title'] ?? '');

        return (new MailMessage)
            ->subject(__('notifications.message_received.subject', ['title' => $title]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.message_received.line', ['sender' => $this->sender(), 'title' => $title]))
            ->line(__('notifications.message_received.privacy'))
            ->action(__('notifications.message_received.action'), $this->frontendUrl($this->path($notifiable)));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'message.received',
            'title' => __('notifications.message_received.title'),
            'body' => __('notifications.message_received.line', ['sender' => $this->sender(), 'title' => (string) ($this->subject['title'] ?? '')]),
            'thread_uuid' => $this->message->thread?->uuid,
            'subject_type' => $this->subject['type'] ?? null,
            'subject_key' => $this->subject['key'] ?? null,
            'opportunity_uuid' => $this->subject['opportunity_uuid'] ?? null,
            'url' => $this->path($notifiable),
        ];
    }
}
