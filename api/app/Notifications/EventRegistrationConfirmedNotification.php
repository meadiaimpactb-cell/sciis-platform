<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\EventRegistration;
use App\Services\EventRegistrationService;
use App\Support\Ics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** MTG-04 — registration confirmation (on-demand mail to the registrant) with the event as an .ics attachment. */
class EventRegistrationConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly EventRegistration $registration)
    {
        $this->onQueue('notifications');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->registration->event;
        $locale = $this->registration->locale ?: 'ar';
        $url = app(EventRegistrationService::class)->publicUrl($event, $locale);
        $tz = (string) config('app.timezone');

        $mail = (new MailMessage)
            ->subject(__('notifications.event_registration.subject', ['title' => $event->title($locale)]))
            ->greeting(__('notifications.greeting', ['name' => $this->registration->name]))
            ->line(__('notifications.event_registration.line', ['title' => $event->title($locale)]));

        if ($event->starts_at !== null) {
            $mail->line(__('notifications.event_registration.when', ['start' => $event->starts_at->timezone($tz)->format('Y-m-d H:i')]));
        }
        if ($event->location) {
            $mail->line(__('notifications.event_registration.where', ['location' => $event->location]));
        }

        return $mail->line(__('notifications.event_registration.calendar'))
            ->action(__('notifications.event_registration.action'), $url)
            ->attachData(Ics::forEvent($event, $url), 'event-'.$event->slug.'.ics', ['mime' => 'text/calendar; charset=utf-8; method=PUBLISH']);
    }
}
