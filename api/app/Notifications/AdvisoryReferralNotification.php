<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AdvisoryReferral;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** EVL-04 — `referred` (to the advisory member) and `opinion` (to the referring staff member); in-app + e-mail. */
class AdvisoryReferralNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly AdvisoryReferral $referral, private readonly string $event)
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
        $o = $this->referral->opportunity;
        $mail = (new MailMessage)
            ->subject(__("reporting.notifications.advisory.{$this->event}_subject", ['ref' => $o->reference_no ?? $o->name]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($this->line());

        if ($this->event === 'referred') {
            $mail->line(__('reporting.notifications.advisory.question', ['question' => $this->referral->question]));
            if ($this->referral->expires_at !== null) {
                $mail->line(__('reporting.notifications.advisory.expires', ['date' => $this->referral->expires_at->setTimezone('Asia/Riyadh')->toDateString()]));
            }
        }

        return $mail->action(__("reporting.notifications.advisory.{$this->event}_action"), rtrim((string) config('app.frontend_url'), '/').$this->path());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'advisory.'.$this->event,
            'title' => __("reporting.notifications.advisory.{$this->event}_title"),
            'body' => $this->line(),
            'opportunity_uuid' => $this->referral->opportunity->uuid,
            'advisory_referral_id' => $this->referral->id,
            'url' => $this->path(),
        ];
    }

    private function path(): string
    {
        return $this->event === 'referred'
            ? '/app/admin/advisory'
            : '/app/admin/opportunities/'.$this->referral->opportunity->uuid.'?tab=advisory';
    }

    private function line(): string
    {
        return __("reporting.notifications.advisory.{$this->event}_line", [
            'name' => $this->referral->opportunity->name,
            'advisor' => $this->referral->advisor?->name ?? '',
        ]);
    }
}
