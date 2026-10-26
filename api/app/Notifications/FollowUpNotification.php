<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Opportunity;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PRJ-01..03, IMP-02, ENT-10 — every follow-up & impact notice (in-app always, mail / SMS per preferences).
 * Texts: `notifications.follow_up.<key>.{subject,line}` with `:name` / `:ref` + the given params; an optional
 * `note` param adds the reviewer's comment line. `$path` is the portal page the notice opens.
 */
class FollowUpNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    /** @param array<string, string|int|float|null> $params */
    public function __construct(
        private readonly string $key,
        private readonly Opportunity $opportunity,
        private readonly string $path,
        private readonly array $params = [],
    ) {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'opportunity';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->text('subject'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->text('line'));

        if (! empty($this->params['note'])) {
            $mail->line(__('notifications.follow_up.note', ['note' => (string) $this->params['note']]));
        }

        return $mail->action(__('notifications.follow_up.action'), $this->frontendUrl($this->path));
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.follow_up.sms', ['ref' => $this->ref()]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'follow_up.'.$this->key,
            'title' => $this->text('subject'),
            'body' => $this->text('line'),
            'opportunity_uuid' => $this->opportunity->uuid,
            'path' => $this->path,
        ] + array_filter($this->params, fn ($v): bool => $v !== null);
    }

    public function key(): string
    {
        return $this->key;
    }

    private function text(string $part): string
    {
        return __("notifications.follow_up.{$this->key}.{$part}", array_map(fn ($v): string => (string) $v, $this->params + [
            'name' => $this->opportunity->name,
            'ref' => $this->ref(),
        ]));
    }

    private function ref(): string
    {
        return (string) ($this->opportunity->reference_no ?? $this->opportunity->name);
    }
}
