<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\EvaluationDecision;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** EVL-03 — the owner is told the evaluation result with its conditions / reasons and the improvement plan. */
class EvaluationDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(private readonly EvaluationDecision $decision)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'opportunity';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $opportunity = $this->decision->opportunity;
        $mail = (new MailMessage)
            ->subject(__('notifications.evaluation_decided.subject', ['ref' => $opportunity->reference_no ?? $opportunity->name]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->line());

        if ($this->decision->conditions) {
            $mail->line(__('notifications.evaluation_decided.conditions', ['conditions' => $this->decision->conditions]));
        }
        if ($this->decision->reasons) {
            $mail->line(__('notifications.evaluation_decided.reasons', ['reasons' => $this->decision->reasons]));
        }

        $plan = (array) ($this->decision->improvement_plan ?? []);
        if ($plan !== []) {
            $mail->line(__('notifications.evaluation_decided.plan'));
            foreach ($plan as $item) {
                $due = ! empty($item['due_at']) ? ' '.__('notifications.evaluation_decided.plan_due', ['date' => $item['due_at']]) : '';
                $mail->line('• '.($item['text'] ?? '').$due);
            }
        }

        return $mail->action(__('notifications.evaluation_decided.action'), $this->frontendUrl('/app/entrepreneur/opportunities/'.$opportunity->uuid));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.evaluation_decided.sms', ['ref' => $this->decision->opportunity->reference_no ?? '']);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'opportunity.evaluation_decided',
            'title' => __('notifications.evaluation_decided.title'),
            'body' => $this->line(),
            'opportunity_uuid' => $this->decision->opportunity->uuid,
            'result' => $this->decision->result->value,
        ];
    }

    private function line(): string
    {
        return __('notifications.evaluation_decided.line', ['name' => $this->decision->opportunity->name, 'result' => $this->decision->result->label()]);
    }
}
