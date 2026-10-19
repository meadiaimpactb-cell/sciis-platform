<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Meeting;
use Carbon\CarbonInterface;

/**
 * MTG-01 — minimal RFC 5545 iCalendar generator (no external dependency).
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

    private static function utc(CarbonInterface $dt): string
    {
        return $dt->copy()->utc()->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $text);
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
