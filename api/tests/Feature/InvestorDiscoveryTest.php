<?php

declare(strict_types=1);

use App\Enums\DocumentKind;
use App\Enums\NominationResponse;
use App\Models\Document;
use App\Models\FileAccessGrant;
use App\Models\InfoRequest;
use App\Models\InvestorNote;
use App\Models\Nomination;
use App\Models\NotificationPreference;
use App\Models\Opportunity;
use App\Models\OpportunityAlert;
use App\Models\SavedFilter;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Notifications\OpportunityAlertNotification;
use App\Notifications\ProfileUpdateReminderNotification;
use App\Notifications\WeeklyDigestNotification;
use App\Services\DocumentService;
use App\Services\MatchingService;
use App\Services\SettingsService;
use App\Support\ArabicGlyphs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** Investor discovery & engagement — OPP-07..12, INV-07, INV-08, MSG-03, MAT-06. */
beforeEach(function (): void {
    app(SettingsService::class)->set('opportunity.access_policy', 'browse_all');
    $this->t = fn (string $group, string $key): TaxonomyTerm => TaxonomyTerm::query()->where('group', $group)->where('key', $key)->firstOrFail();

    $this->education = Opportunity::factory()->eligible()->create(['name' => 'منصة التعليم', 'name_en' => 'Learning Platform', 'sector_id' => ($this->t)('sectors', 'education')->id, 'funding_amount' => 500_000]);
    $this->education->regions()->sync([($this->t)('regions', 'riyadh')->id]);
    $this->education->beneficiaryGroups()->sync([($this->t)('beneficiary_groups', 'youth')->id]);
    $this->education->sdgs()->sync([($this->t)('sdgs', 'sdg_4')->id]);

    $this->health = Opportunity::factory()->eligible()->create(['name' => 'عيادة متنقلة', 'sector_id' => ($this->t)('sectors', 'health')->id, 'funding_amount' => 3_000_000]);
    $this->health->regions()->sync([($this->t)('regions', 'makkah')->id]);
    $this->health->beneficiaryGroups()->sync([($this->t)('beneficiary_groups', 'elderly')->id]);
    $this->health->sdgs()->sync([($this->t)('sdgs', 'sdg_3')->id, ($this->t)('sdgs', 'sdg_4')->id]);

    $this->investor = $this->approvedInvestor();
});

it('filters by beneficiary group and SDG and counts facets over the applied filters (OPP-10)', function (): void {
    $this->actingAs($this->investor, 'api');

    $this->getJson('/api/v1/opportunities?filter[beneficiary]=youth')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $this->education->uuid);
    $this->getJson('/api/v1/opportunities?filter[sdg]=sdg_3')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $this->health->uuid);
    $this->getJson('/api/v1/opportunities?filter[sdg]=sdg_3,sdg_4')->assertOk()->assertJsonCount(2, 'data');

    $unfiltered = $this->getJson('/api/v1/opportunities')->assertOk()->json('meta.counts');
    expect($unfiltered['sector'])->toBe(['education' => 1, 'health' => 1])
        ->and($unfiltered['sdg'])->toBe(['sdg_3' => 1, 'sdg_4' => 2])
        ->and($unfiltered['beneficiary'])->toBe(['elderly' => 1, 'youth' => 1]);

    // sector=health: the other facets only count health; the sector facet still shows the alternatives.
    $counts = $this->getJson('/api/v1/opportunities?filter[sector]=health')->assertOk()->json('meta.counts');
    expect($counts['sector'])->toBe(['education' => 1, 'health' => 1])
        ->and($counts['sdg'])->toBe(['sdg_3' => 1, 'sdg_4' => 1])
        ->and($counts['beneficiary'])->toBe(['elderly' => 1])
        ->and($counts['region'])->toBe(['makkah' => 1])
        ->and($counts['range'])->toBe(['r3' => 1]);
});

it('saves, renames, applies and deletes a search — owner only (OPP-08)', function (): void {
    $this->actingAs($this->investor, 'api');

    $this->postJson('/api/v1/investor/saved-filters', ['name' => 'فارغ', 'filters' => ['sort' => 'need']])
        ->assertStatus(422)->assertJsonValidationErrors(['filters']);

    $id = $this->postJson('/api/v1/investor/saved-filters', ['name' => 'تعليم الشباب', 'filters' => ['beneficiary' => 'youth', 'sdg' => 'sdg_4', 'bogus' => 'x'], 'notify' => true])
        ->assertCreated()
        ->assertJsonPath('data.filters', ['beneficiary' => 'youth', 'sdg' => 'sdg_4'])
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.notify', true)
        ->json('data.id');

    $this->patchJson("/api/v1/investor/saved-filters/{$id}", ['name' => 'شباب', 'notify' => false])->assertOk()
        ->assertJsonPath('data.name', 'شباب')->assertJsonPath('data.notify', false);
    $this->patchJson("/api/v1/investor/saved-filters/{$id}", ['filters' => ['sector' => 'health']])->assertStatus(422);

    $this->getJson('/api/v1/investor/saved-filters')->assertOk()
        ->assertJsonCount(1, 'data.filters')->assertJsonPath('data.profile_alerts', false);

    $other = $this->approvedInvestor();
    $this->actingAs($other, 'api');
    $this->getJson('/api/v1/investor/saved-filters')->assertOk()->assertJsonCount(0, 'data.filters');
    $this->patchJson("/api/v1/investor/saved-filters/{$id}", ['name' => 'سرقة'])->assertNotFound();
    $this->deleteJson("/api/v1/investor/saved-filters/{$id}")->assertNotFound();

    $this->actingAs($this->investor, 'api');
    $this->putJson('/api/v1/investor/alerts/profile', ['enabled' => true])->assertOk()->assertJsonPath('data.profile_alerts', true);
    $this->getJson('/api/v1/investor/saved-filters')->assertJsonPath('data.profile_alerts', true)->assertJsonCount(1, 'data.filters');
    $this->deleteJson("/api/v1/investor/saved-filters/{$id}")->assertOk();
    expect(SavedFilter::query()->where('user_id', $this->investor->id)->count())->toBe(1); // the profile switch only
});

it('alerts investors once per newly listed opportunity, respecting preferences (OPP-07)', function (): void {
    Notification::fake();
    $search = SavedFilter::query()->create(['user_id' => $this->investor->id, 'name' => 'صحة', 'filters' => ['sector' => 'health'], 'notify' => true]);
    $search->forceFill(['created_at' => now()->subDays(2)])->save();
    $quiet = $this->approvedInvestor(); // a search without alerts → nothing
    SavedFilter::query()->create(['user_id' => $quiet->id, 'name' => 'صحة', 'filters' => ['sector' => 'health'], 'notify' => false]);

    $this->artisan('sciis:opportunity-alerts')->assertSuccessful();

    Notification::assertSentTo($this->investor, OpportunityAlertNotification::class, function (OpportunityAlertNotification $n, array $channels): bool {
        $payload = $n->toArray($this->investor);

        return $channels === ['database', 'mail'] && $payload['count'] === 1 && $payload['opportunity_uuid'] === $this->health->uuid;
    });
    Notification::assertNotSentTo($quiet, OpportunityAlertNotification::class);
    expect(OpportunityAlert::query()->where('user_id', $this->investor->id)->pluck('opportunity_id')->all())->toBe([$this->health->id]);

    // second run: nothing new
    Notification::fake();
    $this->artisan('sciis:opportunity-alerts')->assertSuccessful();
    Notification::assertNothingSent();

    // e-mail switched off for alerts → in-app only; nominated opportunities are left to the nomination notice.
    $other = $this->approvedInvestor();
    NotificationPreference::query()->create(['user_id' => $other->id, 'channel' => 'mail', 'category' => 'alerts', 'enabled' => false]);
    SavedFilter::query()->create(['user_id' => $other->id, 'name' => 'الكل', 'filters' => ['search' => 'منصة'], 'notify' => true])->forceFill(['created_at' => now()->subDay()])->save();
    $this->artisan('sciis:opportunity-alerts')->assertSuccessful();
    Notification::assertSentTo($other, OpportunityAlertNotification::class, fn ($n, array $channels): bool => $channels === ['database']);
});

it('alerts on profile matches above the threshold and only under a visible policy (OPP-07, MAT-06)', function (): void {
    Notification::fake();
    $profile = $this->investor->investorProfile;
    $profile->sectors()->sync([($this->t)('sectors', 'education')->id]);
    $profile->regions()->sync([($this->t)('regions', 'riyadh')->id]);
    SavedFilter::query()->create(['user_id' => $this->investor->id, 'kind' => SavedFilter::KIND_PROFILE, 'name' => 'profile', 'filters' => [], 'notify' => true])
        ->forceFill(['created_at' => now()->subDay()])->save();

    app(SettingsService::class)->set('opportunity.access_policy', 'nomination_only');
    $this->artisan('sciis:opportunity-alerts')->assertSuccessful();
    Notification::assertNothingSent();

    app(SettingsService::class)->set('opportunity.access_policy', 'browse_all');
    $this->artisan('sciis:opportunity-alerts')->assertSuccessful();
    Notification::assertSentTo($this->investor, OpportunityAlertNotification::class, fn ($n): bool => $n->toArray($this->investor)['opportunity_uuids'] === [$this->education->uuid]);
});

it('keeps private notes visible to their author only (OPP-09)', function (): void {
    $this->actingAs($this->investor, 'api');
    $base = "/api/v1/opportunities/{$this->education->uuid}/notes";

    $this->postJson($base, ['body' => ''])->assertStatus(422)->assertJsonValidationErrors(['body']);
    $id = $this->postJson($base, ['body' => 'فريق قوي، أسأل عن الإيرادات', 'tags' => ['متابعة', 'متابعة']])->assertCreated()
        ->assertJsonPath('data.tags', ['متابعة'])->json('data.id');
    $this->patchJson("/api/v1/investor/notes/{$id}", ['body' => 'محدثة'])->assertOk()->assertJsonPath('data.body', 'محدثة');
    $this->getJson($base)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'محدثة');

    // nobody else: another investor, the owner, staff
    $other = $this->approvedInvestor();
    $this->actingAs($other, 'api');
    $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
    $this->patchJson("/api/v1/investor/notes/{$id}", ['body' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/investor/notes/{$id}")->assertNotFound();
    $this->actingAs($this->education->owner, 'api');
    $this->getJson($base)->assertForbidden();
    $admin = $this->staff('super_admin');
    $this->actingAs($admin, 'api');
    expect(json_encode($this->getJson("/api/v1/admin/opportunities/{$this->education->uuid}")->json()))->not->toContain('محدثة');
    expect(DB::table('audit_logs')->where('action', 'like', 'investor_note.%')->pluck('after')->implode(' '))->not->toContain('محدثة');

    $this->actingAs($this->investor, 'api');
    $this->deleteJson("/api/v1/investor/notes/{$id}")->assertOk();
    expect(InvestorNote::query()->count())->toBe(0);
});

it('compares 2 to 4 visible opportunities on public fields only (OPP-12)', function (): void {
    $this->actingAs($this->investor, 'api');

    $items = $this->getJson("/api/v1/opportunities/compare?ids={$this->education->uuid},{$this->health->uuid}")->assertOk()->json('data.items');
    expect($items)->toHaveCount(2)
        ->and($items[0]['uuid'])->toBe($this->education->uuid)
        ->and($items[1]['sdgs'])->toHaveCount(2)
        ->and($items[0])->toHaveKeys(['sector', 'stage', 'funding_display', 'beneficiaries_target', 'beneficiary_groups', 'sdgs', 'primary_indicator', 'teaser_summary'])
        ->and($items[0])->not->toHaveKeys(['team', 'owner_contact', 'risks_full', 'documents', 'founders', 'why_now']);
    expect(json_encode($items))->not->toContain('سري:')->not->toContain('داخلي:')->not->toContain('خاص:');

    $this->getJson("/api/v1/opportunities/compare?ids={$this->education->uuid}")->assertStatus(422);
    $hidden = Opportunity::factory()->create(); // draft
    $this->getJson("/api/v1/opportunities/compare?ids={$this->education->uuid},{$hidden->uuid}")->assertNotFound();
});

it('serves the English card fields for the en locale with an Arabic fallback (OPP-11)', function (): void {
    config(['sciis.features.english' => true]);
    $this->actingAs($this->investor, 'api');

    $cards = collect($this->getJson('/api/v1/opportunities', ['Accept-Language' => 'en'])->assertOk()->json('data'))->keyBy('uuid');
    expect($cards[$this->education->uuid]['name'])->toBe('Learning Platform')
        ->and($cards[$this->health->uuid]['name'])->toBe('عيادة متنقلة');
    $this->getJson("/api/v1/opportunities/{$this->education->uuid}", ['Accept-Language' => 'ar'])->assertJsonPath('data.name', 'منصة التعليم');
    $this->getJson('/api/v1/opportunities?search=Learning')->assertJsonCount(1, 'data');

    $owner = $this->entrepreneur();
    $this->actingAs($owner, 'api');
    $uuid = $this->postJson('/api/v1/entrepreneur/opportunities', ['name' => 'مشروعي'])->assertCreated()->json('data.uuid');
    $this->patchJson("/api/v1/entrepreneur/opportunities/{$uuid}", ['basics' => ['name_en' => 'My project', 'short_description_en' => 'Short English text']])->assertOk()
        ->assertJsonPath('data.name_en', 'My project')->assertJsonPath('data.short_description_en', 'Short English text')
        ->assertJsonPath('data.name', 'مشروعي');
});

it('reminds approved investors with a stale profile once per period (INV-07)', function (): void {
    Notification::fake();
    $stale = $this->approvedInvestor();
    DB::table('investor_profiles')->where('user_id', $stale->id)->update(['updated_at' => now()->subDays(200)]);
    DB::table('investor_profiles')->where('user_id', '!=', $stale->id)->update(['updated_at' => now()->subDays(10)]);
    $registered = $this->registeredInvestor();
    DB::table('investor_profiles')->where('user_id', $registered->id)->update(['updated_at' => now()->subDays(400)]);

    $this->artisan('sciis:investor-profile-reminders')->assertSuccessful();
    Notification::assertSentTo($stale, ProfileUpdateReminderNotification::class);
    Notification::assertNotSentTo($this->investor, ProfileUpdateReminderNotification::class);
    Notification::assertNotSentTo($registered, ProfileUpdateReminderNotification::class);

    $row = DB::table('investor_profiles')->where('user_id', $stale->id)->first();
    expect($row->reminder_last_sent_at)->not->toBeNull()
        ->and(now()->diffInDays($row->updated_at, true))->toBeGreaterThan(199); // the "last update" is untouched

    Notification::fake();
    $this->artisan('sciis:investor-profile-reminders')->assertSuccessful();
    Notification::assertNothingSent();

    app(SettingsService::class)->set('investor.profile_reminder_days', 0);
    DB::table('investor_profiles')->update(['reminder_last_sent_at' => null]);
    $this->artisan('sciis:investor-profile-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});

it('watermarks investor image downloads after decryption when the feature is on (INV-08)', function (): void {
    Storage::fake('local');
    $image = imagecreatetruecolor(320, 200);
    imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    $owner = $this->education->owner;
    $document = app(DocumentService::class)->upload(UploadedFile::fake()->createWithContent('photo.png', $png), $owner, $this->education, DocumentKind::Other)->refresh();
    $pdf = app(DocumentService::class)->upload(UploadedFile::fake()->createWithContent('deck.pdf', "%PDF-1.4\n% deck\n"), $owner, $this->education, DocumentKind::Other)->refresh();
    expect($document->encrypted)->toBeTrue();

    FileAccessGrant::query()->create(['opportunity_id' => $this->education->id, 'investor_user_id' => $this->investor->id, 'granted_by_owner_at' => now()]);
    $consent = $this->investor->consents()->create(['type' => 'nda', 'version' => '1.0', 'text_hash' => str_repeat('a', 64), 'accepted_at' => now(), 'subject_type' => 'opportunity', 'subject_id' => $this->education->id]);
    FileAccessGrant::query()->where('investor_user_id', $this->investor->id)->update(['nda_consent_id' => $consent->id]);

    $download = fn (Document $d, User $u): string => $this->get(app(DocumentService::class)->temporaryUrl($d, $u)['url'])->assertOk()->streamedContent();

    config(['sciis.features.watermark' => false]);
    expect($download($document, $this->investor))->toBe($png);

    config(['sciis.features.watermark' => true]);
    $stamped = $download($document, $this->investor);
    $size = getimagesizefromstring($stamped);
    expect($stamped)->not->toBe($png)
        ->and($size[0])->toBe(320)->and($size[1])->toBe(200)->and($size['mime'])->toBe('image/png')
        ->and($download($document, $owner))->toBe($png) // the owner's own file is never stamped
        ->and($download($pdf, $this->investor))->toBe("%PDF-1.4\n% deck\n"); // PDF: needs a PDF library (hook only)

    expect(ArabicGlyphs::visual('سري — CONFIDENTIAL'))->toBe('CONFIDENTIAL — ﻱﺮﺳ')
        ->and(ArabicGlyphs::visual('investor@example.sa'))->toBe('investor@example.sa');
});

it('sends the weekly digest to approved investors who did not opt out (MSG-03)', function (): void {
    Notification::fake();
    Nomination::query()->create(['opportunity_id' => $this->education->id, 'investor_user_id' => $this->investor->id, 'match_score' => 2, 'matched_criteria' => ['sector'], 'sent_at' => now()]);
    InfoRequest::query()->create(['opportunity_id' => $this->health->id, 'investor_user_id' => $this->investor->id, 'subject' => 'سؤال', 'body' => 'تفاصيل السؤال', 'status' => 'sent']);
    SavedFilter::query()->create(['user_id' => $this->investor->id, 'name' => 'صحة', 'filters' => ['sector' => 'health'], 'notify' => false]);

    $optedOut = $this->approvedInvestor();
    Nomination::query()->create(['opportunity_id' => $this->health->id, 'investor_user_id' => $optedOut->id, 'match_score' => 1, 'sent_at' => now()]);
    NotificationPreference::query()->create(['user_id' => $optedOut->id, 'channel' => 'mail', 'category' => 'digest', 'enabled' => false]);
    $idle = $this->approvedInvestor();

    $this->artisan('sciis:investor-digest')->assertSuccessful();

    Notification::assertSentTo($this->investor, WeeklyDigestNotification::class, function (WeeklyDigestNotification $n, array $channels): bool {
        $payload = $n->toArray($this->investor);

        return $channels === ['database', 'mail'] && $payload['matches'] === 1 && $payload['nominations_pending'] === 1 && $payload['info_requests_open'] === 1;
    });
    Notification::assertNotSentTo($optedOut, WeeklyDigestNotification::class);
    Notification::assertNotSentTo($idle, WeeklyDigestNotification::class);

    // investors see the two new preference rows; project owners do not
    $this->actingAs($this->investor, 'api');
    expect(collect($this->getJson('/api/v1/me/notification-preferences')->json('data.preferences'))->pluck('category'))->toContain('alerts')->toContain('digest');
    $this->actingAs($this->entrepreneur(), 'api');
    expect(collect($this->getJson('/api/v1/me/notification-preferences')->json('data.preferences'))->pluck('category'))->not->toContain('digest');
});

it('ranks candidates by a weighted, explained fit with impact and past behaviour (MAT-06)', function (): void {
    $matching = app(MatchingService::class);
    $sector = ($this->t)('sectors', 'education')->id;

    $a = $this->approvedInvestor();
    $a->investorProfile->sectors()->sync([$sector]);
    $a->investorProfile->causes()->sync([($this->t)('causes', 'education_access')->id]); // → sdg_4 + youth

    $b = $this->approvedInvestor();
    $b->investorProfile->sectors()->sync([$sector]);
    // b declined a similar (same sector) opportunity for "sector fit"
    $similar = Opportunity::factory()->eligible()->create(['sector_id' => $sector]);
    Nomination::query()->create(['opportunity_id' => $similar->id, 'investor_user_id' => $b->id, 'match_score' => 1, 'sent_at' => now(), 'response' => NominationResponse::Declined, 'decline_reason_id' => ($this->t)('decline_reasons', 'sector_fit')->id, 'responded_at' => now()]);

    $rows = collect($matching->candidateRows($this->education, 10))->keyBy(fn (array $r): int => $r['investor']['id']);

    expect($rows[$a->id]['match_score'])->toBe(1)
        ->and($rows[$a->id]['weighted_score'])->toBeGreaterThan($rows[$b->id]['weighted_score'])
        ->and($rows[$a->id]['impact_matches'])->not->toBeEmpty()
        ->and($rows[$a->id]['explanation'])->toContain(__('enums.match_criteria.sector'))
        ->and($rows[$b->id]['adjustments'][0]['key'])->toBe('decline')
        ->and($rows[$b->id]['explanation'])->toContain('-10')
        ->and(array_column($rows[$a->id]['score_breakdown'], 'key'))->toContain('impact');
    expect(collect($matching->candidateRows($this->education, 10))->first()['investor']['id'])->toBe($a->id);

    // weights come from settings
    app(SettingsService::class)->set('matching.weight_impact', 0);
    $row = collect($matching->candidateRows($this->education, 10))->firstWhere('investor.id', $a->id);
    expect(collect($row['score_breakdown'])->firstWhere('key', 'impact')['max'])->toBe(0);
});
