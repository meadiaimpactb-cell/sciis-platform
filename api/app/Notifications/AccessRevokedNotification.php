<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Interest;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ENT-06 — the owner revoked a previously approved full-file access. The investor is told (no owner identity);
 * matching staff get the version naming the investor so they can follow up.
 */
class AccessRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences {
        RespectsPreferences::via as preferredChannels;
    }

    public function __construct(private readonly Interest $interest, private readonly bool $forStaff = false, private readonly ?string $reason = null)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'interest';
    }

    /** Staff: in-app + e-mail; the investor: their notification preferences. @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->forStaff ? ['database', 'mail'] : $this->preferredChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $opportunity = $this->interest->opportunity;
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->body());

        if ($this->reason && ! $this->forStaff) {
            $mail->line(__('notifications.access_revoked.reason', ['reason' => $this->reason]));
        }

        return $mail->action(
            __('notifications.access_revoked.action'),
            $this->frontendUrl($this->forStaff ? '/app/admin/interests' : '/app/investor/interests'),
        );
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.access_revoked.sms');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'interest.access_revoked',
            'title' => $this->subject(),
            'body' => $this->body(),
            'interest_id' => $this->interest->id,
            'opportunity_uuid' => $this->interest->opportunity->uuid,
            'reason' => $this->forStaff ? $this->reason : null,
        ];
    }

    private function subject(): string
    {
        $o = $this->interest->opportunity;

        return $this->forStaff
            ? __('notifications.access_revoked.staff_subject', ['ref' => $o->reference_no ?? $o->name])
            : __('notifications.access_revoked.subject', ['name' => $o->name]);
    }

    private function body(): string
    {
        $o = $this->interest->opportunity;

        return $this->forStaff
            ? __('notifications.access_revoked.staff_line', ['name' => $o->name, 'investor' => $this->interest->investor?->name ?? ''])
            : __('notifications.access_revoked.line', ['name' => $o->name]);
    }
}
