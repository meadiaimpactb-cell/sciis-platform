<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Membership;
use App\Models\Opportunity;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADM-08 — reminder sent from a bulk action to someone with a pending request:
 *  - `access_requests`        owner with investor access requests awaiting their decision;
 *  - `membership_completion`  investor whose membership file was sent back for completion;
 *  - `membership_submit`      investor who registered but never submitted the membership application.
 * (Owners with an open completion request get the existing CompletionReminderNotification.)
 */
class PendingRequestReminderNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(
        private readonly string $kind,
        private readonly ?Opportunity $opportunity = null,
        private readonly ?Membership $membership = null,
        private readonly int $count = 0,
    ) {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return $this->kind === 'access_requests' ? 'interest' : 'membership';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__("reporting.notifications.reminders.{$this->kind}_subject"))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->line())
            ->action($this->actionLabel(), $this->frontendUrl($this->path()));
    }

    public function toSms(object $notifiable): string
    {
        return __("reporting.notifications.reminders.{$this->kind}_title");
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return array_filter([
            'type' => 'reminder.'.$this->kind,
            'title' => __("reporting.notifications.reminders.{$this->kind}_title"),
            'body' => $this->line(),
            'opportunity_uuid' => $this->opportunity?->uuid,
            'membership_id' => $this->membership?->id,
            'url' => $this->path(),
        ], fn ($v): bool => $v !== null);
    }

    private function path(): string
    {
        return $this->kind === 'access_requests' ? '/app/entrepreneur/access-requests' : '/app/investor/profile';
    }

    private function actionLabel(): string
    {
        return __($this->kind === 'access_requests' ? 'reporting.notifications.reminders.access_requests_action' : 'reporting.notifications.reminders.membership_action');
    }

    private function line(): string
    {
        return trim(__("reporting.notifications.reminders.{$this->kind}_line", [
            'count' => $this->count,
            'name' => $this->opportunity?->name ?? '',
            'note' => (string) ($this->membership?->completion_note ?? ''),
        ]));
    }
}
