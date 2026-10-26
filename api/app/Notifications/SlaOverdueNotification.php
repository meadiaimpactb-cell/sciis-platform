<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Interest;
use App\Models\Opportunity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

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

        return $mail->action(__('notifications.sla.action'), rtrim((string) config('app.frontend_url'), '/').'/app/admin/opportunities?overdue=1');
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

    /** @param Collection<int, Opportunity> $opportunities */
    public static function fromOpportunities(iterable $opportunities): self
    {
        $items = [];
        foreach ($opportunities as $o) {
            $items[] = self::itemFor($o);
        }

        return new self($items);
    }

    /** @return array{uuid: string, reference_no: ?string, name: string, kind: string, due_at: string} */
    public static function itemFor(Opportunity $o): array
    {
        return [
            'uuid' => $o->uuid,
            'reference_no' => $o->reference_no,
            'name' => $o->name,
            'kind' => $o->overdueKind() ?? 'review',
            'due_at' => $o->slaDueAt()?->toDateString() ?? '',
        ];
    }

    /**
     * ADM-03 — an access request still waiting for the owner's decision past its deadline.
     *
     * @return array{uuid: string, reference_no: ?string, name: string, kind: string, due_at: string}
     */
    public static function itemForOwnerDecision(Interest $interest): array
    {
        $o = $interest->opportunity;

        return [
            'uuid' => $o->uuid,
            'reference_no' => $o->reference_no,
            'name' => $o->name,
            'kind' => 'owner_decision',
            'due_at' => $interest->owner_decision_due_at?->toDateString() ?? '',
        ];
    }
}
