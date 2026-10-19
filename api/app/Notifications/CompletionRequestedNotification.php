<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CompletionRequest;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** ADM-04 / ENT-04 — "المطلوب منك الآن". */
class CompletionRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(private readonly CompletionRequest $request)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'opportunity';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $opportunity = $this->request->opportunity;
        $mail = (new MailMessage)
            ->subject(__('notifications.completion.subject', ['ref' => $opportunity->reference_no ?? $opportunity->name]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.completion.line', ['name' => $opportunity->name]));

        foreach ((array) $this->request->items as $item) {
            $label = $item['field'] ?? ($item['document_kind'] ?? '');
            $mail->line('• '.$label.(isset($item['note']) ? ' — '.$item['note'] : ''));
        }

        if ($this->request->due_at) {
            $mail->line(__('notifications.completion.due', ['date' => $this->request->due_at->toDateString()]));
        }

        return $mail->action(__('notifications.completion.action'), $this->frontendUrl('/entrepreneur/opportunities/'.$opportunity->uuid));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.completion.sms', ['ref' => $this->request->opportunity->reference_no ?? '']);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'opportunity.completion_requested',
            'title' => __('notifications.completion.title'),
            'body' => __('notifications.completion.line', ['name' => $this->request->opportunity->name]),
            'opportunity_uuid' => $this->request->opportunity->uuid,
            'completion_request_id' => $this->request->id,
            'items' => $this->request->items,
            'due_at' => $this->request->due_at?->toIso8601String(),
        ];
    }
}
