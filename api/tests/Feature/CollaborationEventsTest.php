<?php

declare(strict_types=1);

use App\Enums\MeetingParticipantRole;
use App\Enums\MeetingStatus;
use App\Enums\OpportunityStatus;
use App\Models\CalendarFeed;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Meeting;
use App\Models\MessageThread;
use App\Models\NotificationPreference;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\EventRegistrationConfirmedNotification;
use App\Notifications\MessageReceivedNotification;
use App\Notifications\OpportunityStatusChangedNotification;
use App\Services\NotificationService;
use App\Services\WhatsApp\CloudApiWhatsAppSender;
use App\Services\WhatsApp\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** MTG-04 event registration, MTG-05 calendar subscription, NTF-04 WhatsApp channel. */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
});

function collabEvent(array $attributes = []): Event
{
    return Event::query()->create($attributes + [
        'slug' => 'impact-forum-'.fake()->unique()->numberBetween(1, 99999),
        'title_ar' => 'ملتقى الاستثمار الاجتماعي',
        'title_en' => 'Impact Investing Forum',
        'is_published' => true,
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(10),
        'ends_at' => now()->addDays(10)->addHours(3),
        'location' => 'الرياض',
    ]);
}

function collabRegistrant(array $extra = []): array
{
    return $extra + ['name' => 'نورة العتيبي', 'email' => 'noura@example.sa', 'phone' => '0559876543', 'organization' => 'جمعية الأثر', 'website' => ''];
}

// ====================================================================== MTG-04

it('lets staff open on-platform registration, closes it when full and confirms with an .ics', function (): void {
    Notification::fake();
    $event = collabEvent(['registration_url' => 'https://example.org/register']);
    $staff = $this->staff('super_admin');

    $this->getJson("/api/v1/public/events/{$event->slug}")->assertOk()->assertJsonPath('data.registration_enabled', false);
    $this->getJson("/api/v1/public/events/{$event->slug}/registration")->assertOk()
        ->assertJsonPath('data.open', false)->assertJsonPath('data.reason', 'disabled')->assertJsonPath('data.external_url', 'https://example.org/register')
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson("/api/v1/public/events/{$event->slug}/registrations", collabRegistrant())->assertStatus(422)->assertJsonValidationErrors(['registration']);

    $this->actingAs($staff, 'api');
    $this->putJson("/api/v1/admin/events/{$event->id}/registration-settings", ['enabled' => true, 'capacity' => 1, 'deadline' => now()->addDays(5)->toIso8601String()])->assertOk()
        ->assertJsonPath('data.registration.enabled', true)->assertJsonPath('data.registration.seats_left', 1)->assertJsonPath('data.registration.open', true);
    $this->assertDatabaseHas('audit_logs', ['action' => 'event.registration_settings_updated', 'auditable_id' => $event->id]);

    app('auth')->forgetGuards();
    $this->getJson("/api/v1/public/events/{$event->slug}")->assertJsonPath('data.registration_enabled', true);
    $this->postJson("/api/v1/public/events/{$event->slug}/registrations", collabRegistrant())->assertCreated()
        ->assertJsonPath('data.status', 'registered')->assertJsonPath('data.event.slug', $event->slug);

    $registration = EventRegistration::query()->where('email', 'noura@example.sa')->firstOrFail();
    expect($registration->phone)->toBe('+966559876543')->and($registration->user_id)->toBeNull();
    Notification::assertSentOnDemand(EventRegistrationConfirmedNotification::class, function (EventRegistrationConfirmedNotification $n, array $channels, AnonymousNotifiable $notifiable): bool {
        $mail = $n->toMail($notifiable);
        $ics = $mail->rawAttachments[0]['data'] ?? '';

        return array_key_exists('noura@example.sa', (array) $notifiable->routes['mail'])
            && str_contains($ics, 'BEGIN:VCALENDAR') && str_contains($ics, 'BEGIN:VEVENT') && str_ends_with($mail->rawAttachments[0]['name'], '.ics');
    });

    // Full → closed for the next visitor; the same e-mail is told it is already registered.
    $this->getJson("/api/v1/public/events/{$event->slug}/registration")->assertJsonPath('data.reason', 'full')->assertJsonPath('data.seats_left', 0);
    $this->postJson("/api/v1/public/events/{$event->slug}/registrations", collabRegistrant(['email' => 'other@example.sa']))
        ->assertStatus(422)->assertJsonPath('code', 'registration_closed');
    $event->update(['registration_capacity' => 5]);
    $this->postJson("/api/v1/public/events/{$event->slug}/registrations", collabRegistrant(['email' => 'NOURA@example.sa']))
        ->assertStatus(422)->assertJsonValidationErrors(['email']);

    // Honeypot.
    $this->postJson("/api/v1/public/events/{$event->slug}/registrations", collabRegistrant(['email' => 'bot@example.sa', 'website' => 'http://spam']))
        ->assertStatus(422)->assertJsonValidationErrors(['website']);
});

it('closes registration after the deadline or by hand and rate-limits the public form', function (): void {
    $event = collabEvent(['registration_enabled' => true, 'registration_deadline' => now()->subHour()]);
    $this->getJson("/api/v1/public/events/{$event->slug}/registration")->assertJsonPath('data.reason', 'deadline');

    $event->update(['registration_deadline' => null, 'registration_closed_at' => now()]);
    $this->getJson("/api/v1/public/events/{$event->slug}/registration")->assertJsonPath('data.reason', 'closed');

    $past = collabEvent(['registration_enabled' => true, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHour()]);
    $this->getJson("/api/v1/public/events/{$past->slug}/registration")->assertJsonPath('data.reason', 'ended');

    for ($i = 0; $i < 10; $i++) {
        $this->postJson("/api/v1/public/events/{$event->slug}/registrations", ['website' => 'x'])->assertStatus(422);
    }
    $this->postJson("/api/v1/public/events/{$event->slug}/registrations", collabRegistrant())->assertStatus(429);
});

it('links signed-in registrations to the account and prefills the form', function (): void {
    Notification::fake();
    $event = collabEvent(['registration_enabled' => true]);
    $investor = $this->approvedInvestor();

    $this->actingAs($investor, 'api');
    $this->getJson("/api/v1/public/events/{$event->slug}/registration")->assertJsonPath('data.me.registered', false)->assertJsonPath('data.me.email', $investor->email);
    $this->postJson("/api/v1/public/events/{$event->slug}/registrations", collabRegistrant(['email' => $investor->email]))->assertCreated();
    $this->getJson("/api/v1/public/events/{$event->slug}/registration")->assertJsonPath('data.me.registered', true);
    expect(EventRegistration::query()->where('event_id', $event->id)->value('user_id'))->toBe($investor->id);
});

it('lists registrants, marks attendance, frees seats on cancellation and exports to Excel', function (): void {
    Notification::fake();
    $event = collabEvent(['registration_enabled' => true, 'registration_capacity' => 2]);
    foreach (['a@example.sa', 'b@example.sa'] as $email) {
        $this->postJson("/api/v1/public/events/{$event->slug}/registrations", collabRegistrant(['email' => $email]))->assertCreated();
    }

    $officer = $this->staff('matching_officer'); // meetings.manage
    $this->actingAs($officer, 'api');
    $this->getJson('/api/v1/admin/events')->assertOk()->assertJsonPath('data.0.registration.registered_count', 2)->assertJsonPath('data.0.registration.reason', 'full');
    $rows = $this->getJson("/api/v1/admin/events/{$event->id}/registrations?search=a%40example")->assertOk()->assertJsonCount(1, 'data')->json('data');

    $this->patchJson("/api/v1/admin/event-registrations/{$rows[0]['id']}", ['attended' => true])->assertOk()->assertJsonPath('data.attended', true);
    $this->assertDatabaseHas('audit_logs', ['action' => 'event.attendance_marked', 'auditable_id' => $rows[0]['id'], 'user_id' => $officer->id]);
    $this->getJson("/api/v1/admin/events/{$event->id}/registrations")->assertJsonPath('meta.summary.attended', 1)->assertJsonPath('meta.summary.registered', 2);

    $other = EventRegistration::query()->where('email', 'b@example.sa')->firstOrFail();
    $this->patchJson("/api/v1/admin/event-registrations/{$other->id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->getJson("/api/v1/public/events/{$event->slug}/registration")->assertJsonPath('data.open', true)->assertJsonPath('data.seats_left', 1);

    $export = $this->get("/api/v1/admin/events/{$event->id}/registrations/export.xlsx")->assertOk();
    expect((string) $export->headers->get('content-disposition'))->toContain('.xlsx');
    $this->assertDatabaseHas('audit_logs', ['action' => 'event.registrations_exported', 'auditable_id' => $event->id]);

    $this->actingAs($this->staff('evaluator'), 'api');
    $this->getJson("/api/v1/admin/events/{$event->id}/registrations")->assertForbidden();
    $this->putJson("/api/v1/admin/events/{$event->id}/registration-settings", ['enabled' => false])->assertForbidden();
});

it('serves the event as a downloadable .ics file', function (): void {
    $event = collabEvent();
    $response = $this->get("/api/v1/public/events/{$event->slug}/calendar.ics")->assertOk();
    expect((string) $response->headers->get('content-type'))->toContain('text/calendar')
        ->and((string) $response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->getContent())->toContain('SUMMARY:ملتقى الاستثمار الاجتماعي')->toContain('LOCATION:الرياض');
});

// ====================================================================== MTG-05

it('publishes a revocable personal calendar feed of meetings and registered events', function (): void {
    Notification::fake();
    $investor = $this->approvedInvestor();
    $opportunity = Opportunity::factory()->eligible()->create();
    $meeting = Meeting::query()->create([
        'opportunity_id' => $opportunity->id, 'title' => 'اجتماع تعارف', 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour(),
        'organizer_user_id' => $this->staff('matching_officer')->id, 'status' => MeetingStatus::Scheduled, 'video_url' => 'https://meet.example.org/x',
    ]);
    $meeting->participants()->create(['user_id' => $investor->id, 'role' => MeetingParticipantRole::Investor]);
    $event = collabEvent(['registration_enabled' => true]);
    EventRegistration::query()->create(['event_id' => $event->id, 'email' => strtolower($investor->email), 'name' => $investor->name, 'status' => 'registered']);
    $notMine = collabEvent(['title_ar' => 'فعالية أخرى']);

    $this->actingAs($investor, 'api');
    $this->getJson('/api/v1/me/calendar-feed')->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.url', null);
    $feed = $this->postJson('/api/v1/me/calendar-feed')->assertCreated()->assertJsonPath('data.active', true)->json('data');
    expect($feed['webcal_url'])->toStartWith('webcal://')
        ->and($feed['google_url'])->toStartWith('https://calendar.google.com/calendar/render?cid=')
        ->and($feed['outlook_url'])->toStartWith('https://outlook.office.com/calendar/addfromweb?url=');
    $this->getJson('/api/v1/me/calendar-feed')->assertJsonPath('data.url', $feed['url']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'calendar_feed.created', 'user_id' => $investor->id]);

    $path = (string) parse_url($feed['url'], PHP_URL_PATH);
    app('auth')->forgetGuards();
    $ics = $this->get($path)->assertOk();
    expect((string) $ics->headers->get('content-type'))->toContain('text/calendar')
        ->and($ics->getContent())->toContain('SUMMARY:اجتماع تعارف')->toContain('SUMMARY:ملتقى الاستثمار الاجتماعي')->toContain('X-WR-CALNAME')
        ->and($ics->getContent())->not->toContain('فعالية أخرى');
    expect(CalendarFeed::query()->where('user_id', $investor->id)->value('last_accessed_at'))->not->toBeNull();

    // Regenerate → the old URL dies; revoke → no feed.
    $this->actingAs($investor, 'api');
    $new = $this->postJson('/api/v1/me/calendar-feed')->assertCreated()->json('data.url');
    expect($new)->not->toBe($feed['url']);
    $this->get($path)->assertNotFound();
    $this->deleteJson('/api/v1/me/calendar-feed')->assertOk()->assertJsonPath('data.active', false);
    $this->get((string) parse_url($new, PHP_URL_PATH))->assertNotFound();
});

// ====================================================================== NTF-04

it('adds WhatsApp only for opted-in users and only to key alerts', function (): void {
    $user = $this->entrepreneur();
    $service = app(NotificationService::class);

    $matrix = collect($service->preferencesMatrix($user))->keyBy('category');
    expect($matrix['opportunity']['whatsapp'])->toBeFalse()->and($matrix['opportunity']['mail'])->toBeTrue()->and($matrix->has('message'))->toBeTrue();

    $this->actingAs($user, 'api');
    $this->putJson('/api/v1/me/notification-preferences', ['preferences' => [['category' => 'opportunity', 'whatsapp' => true], ['category' => 'message', 'whatsapp' => true]]])->assertOk();
    expect($service->channelsFor($user, 'opportunity'))->not->toContain('whatsapp'); // preferred contact is not WhatsApp

    $user->forceFill(['preferred_contact' => 'whatsapp'])->save();
    expect($service->channelsFor($user, 'opportunity'))->toContain('whatsapp');

    $opportunity = Opportunity::factory()->create(['owner_user_id' => $user->id]);
    $status = new OpportunityStatusChangedNotification($opportunity, OpportunityStatus::Draft, OpportunityStatus::New);
    expect($status->via($user))->toContain('whatsapp');
    // Messages have no WhatsApp template → never on WhatsApp even when switched on.
    $thread = MessageThread::query()->create(['subject_type' => 'opportunity', 'subject_id' => $opportunity->id]);
    $message = $thread->messages()->create(['user_id' => $user->id, 'author_side' => 'party', 'body' => 'x']);
    expect((new MessageReceivedNotification($message, ['title' => 'x'], true))->via($user))->not->toContain('whatsapp');

    // Unverified phone → no WhatsApp.
    $user->forceFill(['phone_verified_at' => null])->save();
    expect($service->channelsFor($user, 'opportunity'))->not->toContain('whatsapp');
});

it('delivers key alerts through the bound WhatsApp driver with the configured template', function (): void {
    $sent = [];
    app()->instance(WhatsAppSender::class, new class($sent) implements WhatsAppSender
    {
        public function __construct(private array &$sent) {}

        public function send(string $to, WhatsAppMessage $message): bool
        {
            $this->sent[] = ['to' => $to, 'message' => $message];

            return true;
        }

        public function driver(): string
        {
            return 'fake';
        }
    });

    $user = User::factory()->entrepreneur()->create(['preferred_contact' => 'whatsapp', 'locale' => 'ar']);
    NotificationPreference::query()->create(['user_id' => $user->id, 'channel' => 'whatsapp', 'category' => 'opportunity', 'enabled' => true]);
    $opportunity = Opportunity::factory()->create(['owner_user_id' => $user->id, 'name' => 'مشروع المياه', 'reference_no' => 'A-2026-0001']);

    $user->notify(new OpportunityStatusChangedNotification($opportunity, OpportunityStatus::Draft, OpportunityStatus::New));

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['to'])->toBe($user->phone)
        ->and($sent[0]['message']->template)->toBe('sciis_opportunity_status')
        ->and($sent[0]['message']->language)->toBe('ar')
        ->and($sent[0]['message']->parameters)->toBe(['مشروع المياه', 'A-2026-0001', OpportunityStatus::New->label()]);

    // An empty template name switches that alert off.
    config(['sciis.whatsapp.templates.opportunity_status' => '']);
    $user->notify(new OpportunityStatusChangedNotification($opportunity, OpportunityStatus::New, OpportunityStatus::UnderReview));
    expect($sent)->toHaveCount(1);
});

it('calls the Meta Cloud API with a template message and reports the driver in the system status', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
    $sender = new CloudApiWhatsAppSender('1234567890', 'secret-token', 'v21.0', 'https://graph.facebook.com');
    $message = new WhatsAppMessage('sciis_meeting_invite', 'ar', ['اجتماع', '2026-11-01 10:00'], 'نص');

    expect($sender->send('+966551112233', $message))->toBeTrue();
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/1234567890/messages'
        && $r->hasHeader('Authorization', 'Bearer secret-token')
        && $r['to'] === '966551112233'
        && $r['template']['name'] === 'sciis_meeting_invite'
        && $r['template']['components'][0]['parameters'][1]['text'] === '2026-11-01 10:00');
    expect((new CloudApiWhatsAppSender('', '', 'v21.0', 'https://graph.facebook.com'))->send('+966551112233', $message))->toBeFalse();

    $this->actingAs($this->staff('super_admin'), 'api');
    $check = collect($this->getJson('/api/v1/admin/system-status')->assertOk()->json('data.checks'))->firstWhere('key', 'whatsapp');
    expect($check)->not->toBeNull()->and($check['value'])->toBe('log')->and($check['ok'])->toBeFalse();
});
