<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Opportunity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** EVL-05 — both evaluations are in and at least one criterion differs by the configured threshold (in-app + e-mail). */
class EvaluationDivergenceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<int, array{key: string, label: string, delta: int}> $criteria */
    public function __construct(private readonly Opportunity $opportunity, private readonly array $criteria, private readonly int $threshold)
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
            ->subject(__('notifications.evaluation_divergence.subject', ['ref' => $this->opportunity->reference_no ?? $this->opportunity->name]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->line());

        foreach (array_slice($this->criteria, 0, 20) as $c) {
            $mail->line(__('notifications.evaluation_divergence.item', ['criterion' => $c['label'], 'delta' => $c['delta']]));
        }

        return $mail->action(
            __('notifications.evaluation_divergence.action'),
            rtrim((string) config('app.frontend_url'), '/').'/app/admin/opportunities/'.$this->opportunity->uuid.'?tab=evaluation',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'evaluation.divergence',
            'title' => __('notifications.evaluation_divergence.title'),
            'body' => $this->line(),
            'opportunity_uuid' => $this->opportunity->uuid,
            'threshold' => $this->threshold,
            'criteria' => $this->criteria,
        ];
    }

    private function line(): string
    {
        return __('notifications.evaluation_divergence.line', ['name' => $this->opportunity->name, 'threshold' => $this->threshold]);
    }
}
