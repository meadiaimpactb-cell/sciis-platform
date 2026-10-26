<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** ACC-08 — invitation e-mail to join an organisation account (on-demand mail; the link expires). */
class OrganizationInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly OrganizationInvitation $invitation,
        private readonly string $organizationName,
        private readonly string $inviterName,
        private readonly string $url,
    ) {
        $this->onQueue('notifications');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.org_invitation.subject', ['organization' => $this->organizationName]))
            ->greeting(__('notifications.greeting', ['name' => $this->invitation->name ?: '']))
            ->line(__('notifications.org_invitation.line', ['inviter' => $this->inviterName, 'organization' => $this->organizationName]))
            ->line(__($this->invitation->can_act ? 'notifications.org_invitation.can_act' : 'notifications.org_invitation.read_only'))
            ->action(__('notifications.org_invitation.action'), $this->url)
            ->line(__('notifications.org_invitation.expires', ['date' => $this->invitation->expires_at->timezone(config('app.timezone'))->format('Y-m-d H:i')]))
            ->line(__('notifications.org_invitation.ignore'));
    }
}
