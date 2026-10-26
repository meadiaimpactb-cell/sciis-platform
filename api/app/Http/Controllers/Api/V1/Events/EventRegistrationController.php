<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\RegisterForEventRequest;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\EventRegistrationService;
use App\Support\Ics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * MTG-04 — public registration endpoints used by the statically prerendered event page (client-side, lazy form):
 * live status, registration (throttle:event-registration + honeypot) and the event as an .ics file.
 */
class EventRegistrationController extends Controller
{
    public function __construct(private readonly EventRegistrationService $registrations) {}

    /** GET /public/events/{slug}/registration — not cached (seats change). */
    public function status(Request $request, string $slug): JsonResponse
    {
        $event = Event::query()->published()->where('slug', $slug)->firstOrFail();
        $status = $this->registrations->status($event);
        $user = $this->signedIn($request);

        $me = null;
        if ($user !== null) {
            $registration = EventRegistration::query()->where('event_id', $event->id)->active()
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('email', strtolower($user->email)))->first();
            $me = [
                'registered' => $registration !== null,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'organization' => $user->organization?->name,
            ];
        }

        return response()->json(['data' => [
            'enabled' => $status['enabled'],
            'open' => $status['open'],
            'reason' => $status['reason'],
            'deadline' => $status['deadline'],
            'capacity' => $status['capacity'],
            'seats_left' => $status['seats_left'],
            'external_url' => $event->registration_url,
            'me' => $me,
        ]])->header('Cache-Control', 'no-store');
    }

    /** POST /public/events/{slug}/registrations */
    public function store(RegisterForEventRequest $request, string $slug): JsonResponse
    {
        $event = Event::query()->published()->where('slug', $slug)->firstOrFail();
        $registration = $this->registrations->register($event, $request->safe()->except('website'), $this->signedIn($request), $request);

        return response()->json([
            'data' => [
                'uuid' => $registration->uuid,
                'status' => $registration->status,
                'email' => $registration->email,
                'event' => [
                    'slug' => $event->slug,
                    'title' => $event->title(),
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'ends_at' => $event->ends_at?->toIso8601String(),
                    'location' => $event->location,
                ],
            ],
            'message' => __('messages.events.registered', ['email' => $registration->email]),
        ], 201);
    }

    /** GET /public/events/{slug}/calendar.ics */
    public function ics(string $slug): Response
    {
        $event = Event::query()->published()->where('slug', $slug)->firstOrFail();
        $url = $this->registrations->publicUrl($event, app()->getLocale());

        return response(Ics::forEvent($event, $url), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="event-'.$event->slug.'.ics"',
        ]);
    }

    /** The signed-in visitor (cookie session or bearer token), if any — the routes themselves are public. */
    private function signedIn(Request $request): ?User
    {
        try {
            $user = $request->user('sanctum');
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User && $user->isActive() ? $user : null;
    }
}
