<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Models\CalendarFeed;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Meeting;
use App\Models\User;
use App\Support\Ics;
use Illuminate\Support\Str;

/**
 * MTG-05 — personal calendar subscription: a secret tokenised iCal URL per user (revocable, regenerable) listing their
 * meetings and the events they registered for. Works with Google Calendar ("From URL") and Microsoft 365 / Outlook
 * ("Subscribe from web") — no OAuth app needed.
 */
class CalendarFeedService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventRegistrationService $events,
    ) {}

    public function feedOf(User $user): ?CalendarFeed
    {
        return CalendarFeed::query()->where('user_id', $user->id)->first();
    }

    /** Creates the feed or replaces its token (the previous URL stops working). */
    public function regenerate(User $user): CalendarFeed
    {
        $token = Str::random(48);
        $existing = $this->feedOf($user);
        $feed = CalendarFeed::query()->updateOrCreate(['user_id' => $user->id], [
            'token_hash' => CalendarFeed::hashToken($token),
            'token' => $token,
            'last_accessed_at' => null,
        ]);
        $this->audit->log($existing ? 'calendar_feed.regenerated' : 'calendar_feed.created', $feed, null, null, $user);

        return $feed;
    }

    public function revoke(User $user): void
    {
        $feed = $this->feedOf($user);
        if ($feed !== null) {
            $this->audit->log('calendar_feed.revoked', $feed, null, null, $user);
            $feed->delete();
        }
    }

    public function findByToken(string $token): ?CalendarFeed
    {
        return $token === '' ? null : CalendarFeed::query()->with('user')->where('token_hash', CalendarFeed::hashToken($token))->first();
    }

    /** @return array<string, mixed> GET /me/calendar-feed */
    public function payload(?CalendarFeed $feed): array
    {
        if ($feed === null) {
            return ['active' => false, 'url' => null, 'webcal_url' => null, 'google_url' => null, 'outlook_url' => null, 'outlook_live_url' => null, 'created_at' => null, 'last_accessed_at' => null];
        }

        $url = rtrim((string) config('app.url'), '/').'/api/v1/calendar/'.$feed->token.'.ics';
        $webcal = (string) preg_replace('#^https?://#', 'webcal://', $url);
        $name = rawurlencode((string) __('messages.calendar.feed_name'));

        return [
            'active' => true,
            'url' => $url,
            'webcal_url' => $webcal,
            'google_url' => 'https://calendar.google.com/calendar/render?cid='.rawurlencode($webcal),
            'outlook_url' => 'https://outlook.office.com/calendar/addfromweb?url='.rawurlencode($url).'&name='.$name,
            'outlook_live_url' => 'https://outlook.live.com/calendar/0/addfromweb?url='.rawurlencode($url).'&name='.$name,
            'created_at' => $feed->updated_at?->toIso8601String(),
            'last_accessed_at' => $feed->last_accessed_at?->toIso8601String(),
        ];
    }

    /** The iCalendar body of a feed: meetings (90 days back → future) and active event registrations. */
    public function render(CalendarFeed $feed): string
    {
        $user = $feed->user;
        app()->setLocale($user->locale === 'en' ? 'en' : 'ar');

        if ($feed->last_accessed_at === null || $feed->last_accessed_at->lt(now()->subMinutes(10))) {
            $feed->forceFill(['last_accessed_at' => now()])->saveQuietly();
        }

        $meetings = Meeting::query()->with('opportunity')
            ->whereHas('participants', fn ($p) => $p->where('user_id', $user->id))
            ->where('starts_at', '>=', now()->subDays(90))
            ->where('status', '!=', MeetingStatus::Cancelled->value)
            ->orderBy('starts_at')->limit(500)->get();

        $events = Event::query()->published()->whereNotNull('starts_at')
            ->whereHas('registrations', fn ($r) => $r->where('status', EventRegistration::STATUS_REGISTERED)
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('email', strtolower($user->email))))
            ->where('starts_at', '>=', now()->subDays(90))
            ->orderBy('starts_at')->limit(200)->get()
            ->map(fn (Event $e): array => ['event' => $e, 'url' => $this->events->publicUrl($e, $user->locale === 'en' ? 'en' : 'ar')]);

        return Ics::feed((string) __('messages.calendar.feed_name'), $meetings, $events);
    }
}
