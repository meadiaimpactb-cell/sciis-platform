<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use App\Notifications\Concerns\RespectsPreferences;
use App\Services\WhatsApp\WhatsAppMessage;
use App\Support\Ics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** MTG-01 — meeting invitation with .ics attachment. */
class MeetingInviteNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(private readonly Meeting $meeting, private readonly bool $isUpdate = false)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'meeting';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $meeting = $this->meeting->loadMissing(['participants.user', 'organizer', 'opportunity']);
        $attendees = $meeting->participants
            ->map(fn ($p): ?array => $p->user ? ['name' => $p->user->name, 'email' => $p->user->email] : null)
            ->filter()
            ->values()
            ->all();

        $ics = Ics::forMeeting($meeting, $attendees);
        $key = $this->isUpdate ? 'updated' : 'invite';

        $mail = (new MailMessage)
            ->subject(__("notifications.meeting.{$key}.subject", ['title' => $meeting->title]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__("notifications.meeting.{$key}.line", ['title' => $meeting->title]))
            ->line(__('notifications.meeting.when', ['start' => $meeting->starts_at->timezone(config('app.timezone'))->format('Y-m-d H:i'), 'end' => $meeting->ends_at->timezone(config('app.timezone'))->format('H:i')]));

        if ($meeting->video_url) {
            $mail->line(__('notifications.meeting.video', ['url' => $meeting->video_url]));
        } elseif ($meeting->location) {
            $mail->line(__('notifications.meeting.location', ['location' => $meeting->location]));
        }

        return $mail->attachData($ics, 'meeting-'.$meeting->id.'.ics', ['mime' => 'text/calendar; charset=utf-8; method=REQUEST']);
    }

    public function toSms(object $notifiable): string
    {
        return __('notifications.meeting.sms', ['title' => $this->meeting->title, 'when' => $this->meeting->starts_at->timezone(config('app.timezone'))->format('Y-m-d H:i')]);
    }

    /** NTF-04 — template `meeting_invite`: {{1}} title, {{2}} date-time (Riyadh). */
    public function toWhatsApp(object $notifiable): ?WhatsAppMessage
    {
        return WhatsAppMessage::forKey('meeting_invite', (string) ($notifiable->locale ?? 'ar'), [
            $this->meeting->title, $this->meeting->starts_at->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ], $this->toSms($notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->isUpdate ? 'meeting.updated' : 'meeting.invite',
            'title' => __('notifications.meeting.title'),
            'body' => $this->meeting->title,
            'meeting_id' => $this->meeting->id,
            'opportunity_uuid' => $this->meeting->opportunity?->uuid,
            'starts_at' => $this->meeting->starts_at->toIso8601String(),
            'ends_at' => $this->meeting->ends_at->toIso8601String(),
            'video_url' => $this->meeting->video_url,
            'location' => $this->meeting->location,
        ];
    }
}
