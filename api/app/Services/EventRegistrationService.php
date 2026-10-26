<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\EventRegistrationConfirmedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * MTG-04 — optional on-platform event registration: capacity, deadline, manual close; registration closes by itself
 * when full (a cancellation frees the seat). Guests and signed-in users; confirmation e-mail with an .ics file.
 * The external `registration_url` keeps working for events that do not use the platform form.
 */
class EventRegistrationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{enabled: bool, open: bool, reason: ?string, deadline: ?string, capacity: ?int, seats_left: ?int, registered_count: int} */
    public function status(Event $event): array
    {
        $count = $event->registrations()->active()->count();
        $capacity = $event->registration_capacity;
        $end = $event->ends_at ?? $event->starts_at;

        $reason = match (true) {
            ! $event->registration_enabled => 'disabled',
            $end !== null && $end->isPast() => 'ended',
            $event->registration_closed_at !== null => 'closed',
            $event->registration_deadline !== null && $event->registration_deadline->isPast() => 'deadline',
            $capacity !== null && $count >= $capacity => 'full',
            default => null,
        };

        return [
            'enabled' => (bool) $event->registration_enabled,
            'open' => $reason === null,
            'reason' => $reason,
            'deadline' => $event->registration_deadline?->toIso8601String(),
            'capacity' => $capacity,
            'seats_left' => $capacity !== null ? max(0, $capacity - $count) : null,
            'registered_count' => $count,
        ];
    }

    /** @param array{name: string, email: string, phone?: ?string, organization?: ?string} $data */
    public function register(Event $event, array $data, ?User $user, Request $request): EventRegistration
    {
        $email = Str::lower(trim($data['email']));

        $registration = DB::transaction(function () use ($event, $data, $user, $request, $email): EventRegistration {
            // Serialise registrations of one event so the last seat is never sold twice.
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $status = $this->status($locked);
            if (! $status['open']) {
                $message = __('messages.events.closed.'.$status['reason']);
                throw new DomainException($message, 'registration_closed', 422, ['registration' => [$message]]);
            }

            $existing = EventRegistration::query()->where('event_id', $event->id)->where('email', $email)->first();
            if ($existing?->isActive()) {
                throw new DomainException(__('messages.events.already_registered'), 'already_registered', 422, ['email' => [__('messages.events.already_registered')]]);
            }

            $attributes = [
                'user_id' => $user?->id,
                'name' => trim($data['name']),
                'phone' => $data['phone'] ?? null,
                'organization' => isset($data['organization']) && trim((string) $data['organization']) !== '' ? trim((string) $data['organization']) : null,
                'status' => EventRegistration::STATUS_REGISTERED,
                'locale' => app()->getLocale() === 'en' ? 'en' : 'ar',
                'cancelled_at' => null,
                'ip' => $request->ip(),
            ];
            $registration = $existing
                ? tap($existing)->update($attributes)
                : EventRegistration::query()->create($attributes + ['event_id' => $event->id, 'email' => $email]);

            $this->audit->log('event.registration_created', $registration, null, ['event_id' => $event->id, 'member' => $user !== null, 'reopened' => $existing !== null], $user);

            return $registration;
        });

        $this->sendConfirmation($registration->setRelation('event', $event));

        return $registration;
    }

    public function sendConfirmation(EventRegistration $registration): void
    {
        Notification::route('mail', [$registration->email => $registration->name])
            ->notify((new EventRegistrationConfirmedNotification($registration))->locale($registration->locale ?: 'ar'));
    }

    /** @param array{enabled: bool, capacity?: ?int, deadline?: ?string, closed?: bool} $data */
    public function updateSettings(Event $event, array $data, User $actor): Event
    {
        $before = $event->only(['registration_enabled', 'registration_capacity', 'registration_deadline', 'registration_closed_at']);

        $event->registration_enabled = (bool) $data['enabled'];
        if (array_key_exists('capacity', $data)) {
            $event->registration_capacity = $data['capacity'] !== null ? (int) $data['capacity'] : null;
        }
        if (array_key_exists('deadline', $data)) {
            $event->registration_deadline = $data['deadline'];
        }
        if (array_key_exists('closed', $data)) {
            $event->registration_closed_at = $data['closed'] ? ($event->registration_closed_at ?? now()) : null;
        }
        $event->save();

        $this->audit->log('event.registration_settings_updated', $event, array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v, $before), [
            'registration_enabled' => $event->registration_enabled,
            'registration_capacity' => $event->registration_capacity,
            'registration_deadline' => $event->registration_deadline?->toIso8601String(),
            'registration_closed_at' => $event->registration_closed_at?->toIso8601String(),
        ], $actor);

        return $event;
    }

    /** @param array{attended?: bool, status?: string} $data */
    public function updateRegistration(EventRegistration $registration, array $data, User $actor): EventRegistration
    {
        $before = ['status' => $registration->status, 'attended' => $registration->attended_at !== null];

        if (array_key_exists('status', $data)) {
            $registration->status = $data['status'];
            $registration->cancelled_at = $data['status'] === EventRegistration::STATUS_CANCELLED ? ($registration->cancelled_at ?? now()) : null;
        }
        if (array_key_exists('attended', $data)) {
            $registration->attended_at = $data['attended'] ? ($registration->attended_at ?? now()) : null;
            $registration->attendance_marked_by = $actor->id;
        }
        $registration->save();

        $after = ['status' => $registration->status, 'attended' => $registration->attended_at !== null];
        $action = $before['attended'] !== $after['attended'] ? ($after['attended'] ? 'event.attendance_marked' : 'event.attendance_unmarked') : 'event.registration_updated';
        $this->audit->log($action, $registration, $before, $after, $actor);

        return $registration;
    }

    /** @return array<string, mixed> admin view of a registration */
    public function registrationArray(EventRegistration $r): array
    {
        return [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'name' => $r->name,
            'email' => $r->email,
            'phone' => $r->phone,
            'organization' => $r->organization,
            'status' => $r->status,
            'attended' => $r->attended_at !== null,
            'attended_at' => $r->attended_at?->toIso8601String(),
            'is_member' => $r->user_id !== null,
            'created_at' => $r->created_at?->toIso8601String(),
            'cancelled_at' => $r->cancelled_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> admin summary of an event */
    public function eventArray(Event $event): array
    {
        $status = $this->status($event);

        return [
            'id' => $event->id,
            'slug' => $event->slug,
            'title' => $event->title(),
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'location' => $event->location,
            'is_published' => (bool) $event->is_published,
            'registration_url' => $event->registration_url,
            'registration' => [
                'enabled' => $status['enabled'],
                'capacity' => $status['capacity'],
                'deadline' => $status['deadline'],
                'closed' => $event->registration_closed_at !== null,
                'open' => $status['open'],
                'reason' => $status['reason'],
                'registered_count' => $status['registered_count'],
                'attended_count' => $event->registrations()->active()->whereNotNull('attended_at')->count(),
                'seats_left' => $status['seats_left'],
            ],
        ];
    }

    /** Public URL of the event page (for the .ics / e-mail). */
    public function publicUrl(Event $event, string $locale = 'ar'): string
    {
        return rtrim((string) config('app.frontend_url'), '/').($locale === 'en' ? '/en' : '').'/events/'.$event->slug;
    }
}
