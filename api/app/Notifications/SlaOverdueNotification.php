<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Opportunity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** ADM-03 — staff reminder for overdue SLA items (database + mail; no SMS). */
class SlaOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<int, array{uuid: string, reference_no: ?string, name: string, kind: string, due_at: string}> $items */
    public function __construct(private readonly array $items)
    {
        $this->onQueue('notifications');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('notifications.sla.subject', ['count' => count($this->items)]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.sla.line'));

        foreach (array_slice($this->items, 0, 20) as $item) {
            $mail->line(sprintf('• %s — %s (%s: %s)', $item['reference_no'] ?? '', $item['name'], __('notifications.sla.kinds.'.$item['kind']), $item['due_at']));
        }

        return $mail->action(__('notifications.sla.action'), rtrim((string) config('app.frontend_url'), '/').'/admin/opportunities?overdue=1');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sla.overdue',
            'title' => __('notifications.sla.subject', ['count' => count($this->items)]),
            'body' => __('notifications.sla.line'),
            'items' => $this->items,
        ];
    }

    /** @param \Illuminate\Support\Collection<int, Opportunity> $opportunities */
    public static function fromOpportunities(iterable $opportunities): self
    {
        $items = [];
        foreach ($opportunities as $o) {
            $kind = $o->overdueKind() ?? 'review';
            $due = match ($kind) {
                'completion' => $o->completion_due_at,
                'evaluation' => $o->evaluation_due_at,
                default => $o->review_due_at,
            };
            $items[] = [
                'uuid' => $o->uuid,
                'reference_no' => $o->reference_no,
                'name' => $o->name,
                'kind' => $kind,
                'due_at' => $due?->toDateString() ?? '',
            ];
        }

        return new self($items);
    }
}
