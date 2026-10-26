<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Agreement;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AGR-03/05 — agreement workflow notices (in-app + mail; SMS for the parties' signature steps):
 * review_requested → legal reviewers · approved / changes_requested → the drafting staff ·
 * sent_for_signature → both parties · signed → both parties and the drafting staff.
 */
class AgreementNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public const EVENTS = ['review_requested', 'approved', 'changes_requested', 'sent_for_signature', 'signed'];

    public function __construct(private readonly Agreement $agreement, private readonly string $event, private readonly ?string $note = null)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'opportunity';
    }

    /** @return array<string, string> */
    private function params(): array
    {
        $agreement = $this->agreement->loadMissing(['opportunity', 'currentVersion']);

        return [
            'number' => $agreement->number(),
            'title' => (string) $agreement->title,
            'name' => (string) ($agreement->opportunity?->name ?? ''),
            'version' => (string) ($agreement->currentVersion?->version ?? ''),
        ];
    }

    /** Deep link in the recipient's portal. */
    private function path(object $notifiable): string
    {
        $uuid = $this->agreement->uuid;

        return match (true) {
            $notifiable instanceof User && $notifiable->isStaff() => "/app/admin/agreements/{$uuid}",
            $notifiable instanceof User && $notifiable->isEntrepreneur() => "/app/entrepreneur/agreements/{$uuid}",
            default => "/app/investor/agreements/{$uuid}",
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->params();
        $mail = (new MailMessage)
            ->subject(__("notifications.agreement.{$this->event}.subject", $p))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__("notifications.agreement.{$this->event}.line", $p));

        if ($this->note !== null && trim($this->note) !== '') {
            $mail->line(__('notifications.agreement.note', ['note' => $this->note]));
        }

        return $mail->action(__("notifications.agreement.{$this->event}.action"), $this->frontendUrl($this->path($notifiable)));
    }

    public function toSms(object $notifiable): ?string
    {
        if (! in_array($this->event, ['sent_for_signature', 'signed'], true) || ($notifiable instanceof User && $notifiable->isStaff())) {
            return null;
        }

        return __("notifications.agreement.{$this->event}.sms", $this->params());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $p = $this->params();

        return [
            'type' => 'agreement.'.$this->event,
            'title' => __("notifications.agreement.{$this->event}.subject", $p),
            'body' => __("notifications.agreement.{$this->event}.line", $p),
            'agreement_uuid' => $this->agreement->uuid,
            'agreement_no' => $p['number'],
            'link' => $this->path($notifiable),
        ];
    }
}
