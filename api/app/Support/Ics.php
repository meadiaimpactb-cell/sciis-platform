<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Event;
use App\Models\Meeting;
use Carbon\CarbonInterface;

/**
 * MTG-01 / MTG-04 / MTG-05 — minimal RFC 5545 iCalendar generator (no external dependency): meeting invitations,
 * event confirmations and the personal subscription feed.
 */
final class Ics
{
    /** @param array<int, array{name?: string, email: string}> $attendees */
    public static function forMeeting(Meeting $meeting, array $attendees = []): string
    {
        $uid = sprintf('meeting-%d@%s', $meeting->id, parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'sciis.local');
        $location = $meeting->video_url ?: ($meeting->location ?: '');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//SCIIS//Platform//AR',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.self::utc(now()),
            'DTSTART:'.self::utc($meeting->starts_at),
            'DTEND:'.self::utc($meeting->ends_at),
            'SUMMARY:'.self::escape($meeting->title),
            'DESCRIPTION:'.self::escape(__('notifications.meeting.ics_description', ['ref' => $meeting->opportunity?->reference_no ?? ''])),
        ];

        if ($location !== '') {
            $lines[] = 'LOCATION:'.self::escape($location);
        }
        if ($meeting->video_url) {
            $lines[] = 'URL:'.$meeting->video_url;
        }
        if ($meeting->organizer?->email) {
            $lines[] = 'ORGANIZER;CN='.self::escape($meeting->organizer->name).':mailto:'.$meeting->organizer->email;
        }
        foreach ($attendees as $attendee) {
            $cn = isset($attendee['name']) ? ';CN='.self::escape($attendee['name']) : '';
            $lines[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;RSVP=TRUE'.$cn.':mailto:'.$attendee['email'];
        }

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'SEQUENCE:0';
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'TRIGGER:-PT30M';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:Reminder';
        $lines[] = 'END:VALARM';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** MTG-04 — a CMS event as a single-event calendar (registration confirmation, "download .ics"). */
    public static function forEvent(Event $event, ?string $url = null): string
    {
        return self::calendar([self::eventVevent($event, $url)], 'PUBLISH');
    }

    /**
     * MTG-05 — personal subscription feed (Google Calendar / Microsoft 365 "subscribe from URL"): the user's meetings
     * and registered events as one VCALENDAR.
     *
     * @param  iterable<Meeting>  $meetings
     * @param  iterable<array{event: Event, url: ?string}>  $events
     */
    public static function feed(string $name, iterable $meetings, iterable $events): string
    {
        $vevents = [];
        foreach ($meetings as $meeting) {
            $location = $meeting->video_url ?: ($meeting->location ?: '');
            $vevents[] = self::vevent([
                'uid' => sprintf('meeting-%d@%s', $meeting->id, self::host()),
                'start' => $meeting->starts_at,
                'end' => $meeting->ends_at,
                'summary' => $meeting->title,
                'description' => __('notifications.meeting.ics_description', ['ref' => $meeting->opportunity?->reference_no ?? '']),
                'location' => $location,
                'url' => $meeting->video_url,
                'status' => $meeting->status?->value === 'cancelled' ? 'CANCELLED' : 'CONFIRMED',
                'stamp' => $meeting->updated_at,
            ]);
        }
        foreach ($events as $row) {
            $vevents[] = self::eventVevent($row['event'], $row['url'] ?? null);
        }

        return self::calendar($vevents, 'PUBLISH', $name);
    }

    /** @return list<string> */
    private static function eventVevent(Event $event, ?string $url): array
    {
        $start = $event->starts_at ?? now();

        return self::vevent([
            'uid' => sprintf('event-%d@%s', $event->id, self::host()),
            'start' => $start,
            'end' => $event->ends_at ?? $start->copy()->addHours(2),
            'summary' => $event->title(),
            'description' => $url ?? '',
            'location' => (string) ($event->location ?? ''),
            'url' => $url,
            'status' => 'CONFIRMED',
            'stamp' => $event->updated_at,
        ]);
    }

    /**
     * @param  array{uid: string, start: CarbonInterface, end: CarbonInterface, summary: string, description?: string, location?: string, url?: ?string, status?: string, stamp?: ?CarbonInterface}  $f
     * @return list<string>
     */
    private static function vevent(array $f): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$f['uid'],
            'DTSTAMP:'.self::utc($f['stamp'] ?? now()),
            'DTSTART:'.self::utc($f['start']),
            'DTEND:'.self::utc($f['end']),
            'SUMMARY:'.self::escape($f['summary']),
        ];
        if (($f['description'] ?? '') !== '') {
            $lines[] = 'DESCRIPTION:'.self::escape((string) $f['description']);
        }
        if (($f['location'] ?? '') !== '') {
            $lines[] = 'LOCATION:'.self::escape((string) $f['location']);
        }
        if (! empty($f['url'])) {
            $lines[] = 'URL:'.$f['url'];
        }
        $lines[] = 'STATUS:'.($f['status'] ?? 'CONFIRMED');
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** @param list<list<string>> $vevents */
    private static function calendar(array $vevents, string $method, ?string $name = null): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SCIIS//Platform//AR', 'CALSCALE:GREGORIAN', 'METHOD:'.$method];
        if ($name !== null) {
            $lines[] = 'X-WR-CALNAME:'.self::escape($name);
            $lines[] = 'X-WR-TIMEZONE:'.config('app.timezone', 'Asia/Riyadh');
            $lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
            $lines[] = 'X-PUBLISHED-TTL:PT1H';
        }
        foreach ($vevents as $vevent) {
            array_push($lines, ...$vevent);
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function host(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'sciis.local';
    }

    private static function utc(CarbonInterface $dt): string
    {
        return $dt->copy()->utc()->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    }

    /** RFC 5545 §3.1: lines longer than 75 octets are folded without splitting multibyte characters. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out .= $current."\r\n ";
                $current = '';
                $limit = 74; // continuation lines start with a space
            }
            $current .= $char;
        }

        return $out.$current;
    }
}
