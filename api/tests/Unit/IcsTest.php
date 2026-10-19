<?php

declare(strict_types=1);

use App\Models\Meeting;
use App\Models\Opportunity;
use App\Support\Ics;

it('generates a valid iCalendar payload for a meeting (MTG-01)', function (): void {
    $opportunity = Opportunity::factory()->eligible()->create();
    $organizer = $this->staff('matching_officer');
    $meeting = Meeting::query()->create([
        'opportunity_id' => $opportunity->id, 'title' => 'اجتماع تعريفي; أول', 'starts_at' => '2026-11-01 10:00:00', 'ends_at' => '2026-11-01 11:00:00',
        'video_url' => 'https://meet.example.test/abc', 'organizer_user_id' => $organizer->id, 'status' => 'scheduled',
    ]);

    $ics = Ics::forMeeting($meeting, [['name' => 'م', 'email' => 'a@b.c']]);

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toContain('BEGIN:VEVENT')
        ->toContain('DTSTART:20261101T')
        ->toContain('SUMMARY:اجتماع تعريفي\; أول')
        ->toContain('ATTENDEE;ROLE=REQ-PARTICIPANT;RSVP=TRUE;CN=م:mailto:a@b.c')
        ->toContain('URL:https://meet.example.test/abc')
        ->toEndWith("END:VCALENDAR\r\n");
});
