<?php

declare(strict_types=1);

use App\Enums\IdentityVerificationStatus;
use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Exceptions\DomainException;
use App\Models\FormSchema;
use App\Models\IdentityVerification;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\FormSchemaService;
use App\Services\Identity\FakeIdentityVerifier;
use App\Services\Identity\IdentityVerifier;
use App\Services\Identity\NafathIdentityVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * FRM-01 (additional questions builder + answers), UX-05 (calendar preference), BRD-04 (theme preference),
 * ACC-09 (national identity verification adapter, fake + Nafath drivers).
 */
function investorQuestions(): array
{
    return [
        ['id' => 'q_fund', 'type' => 'yes_no', 'label_ar' => 'هل تستثمر عبر صندوق؟', 'label_en' => 'Do you invest through a fund?', 'required' => true],
        ['id' => 'q_fund_name', 'type' => 'short_text', 'label_ar' => 'اسم الصندوق', 'required' => true, 'show_when' => ['question' => 'q_fund', 'equals' => 'yes']],
        ['id' => 'q_focus', 'type' => 'multiple_choice', 'label_ar' => 'مجالات التركيز', 'required' => false, 'options' => [
            ['value' => 'health', 'label_ar' => 'الصحة', 'label_en' => 'Health'],
            ['value' => 'education', 'label_ar' => 'التعليم', 'label_en' => 'Education'],
        ]],
        ['id' => 'q_ticket', 'type' => 'number', 'label_ar' => 'متوسط التذكرة', 'required' => false],
        ['id' => 'q_retired', 'type' => 'long_text', 'label_ar' => 'سؤال موقوف', 'required' => true, 'active' => false],
    ];
}

function publishForm(string $key, array $questions): FormSchema
{
    return app(FormSchemaService::class)->publish($key, $questions, User::factory()->staff('super_admin')->create());
}

// ---------------------------------------------------------------- FRM-01 builder

it('publishes versioned additional-question schemas for staff with settings.manage only', function (): void {
    $admin = $this->staff('super_admin');
    $this->actingAs($admin, 'api');

    $this->postJson('/api/v1/admin/forms/investor_profile/publish', ['questions' => investorQuestions()])
        ->assertCreated()->assertJsonPath('data.version', 1)->assertJsonCount(5, 'data.questions');
    $second = investorQuestions();
    $second[0]['label_ar'] = 'هل تستثمر من خلال صندوق استثماري؟';
    $this->postJson('/api/v1/admin/forms/investor_profile/publish', ['questions' => $second])
        ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.questions.0.label_ar', 'هل تستثمر من خلال صندوق استثماري؟')
        ->assertJsonPath('data.versions.0.version', 2)->assertJsonPath('data.versions.1.is_active', false);

    expect(FormSchema::query()->where('key', 'investor_profile')->where('is_active', true)->pluck('version')->all())->toBe([2]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'form_schema.published', 'user_id' => $admin->id]);

    $index = $this->getJson('/api/v1/admin/forms')->assertOk()->json('data.forms');
    expect(collect($index)->firstWhere('key', 'investor_profile'))->toMatchArray(['version' => 2, 'questions_count' => 5, 'active_count' => 4]);

    // public read: active questions only, unknown / staff-only keys → 404
    $this->getJson('/api/v1/forms/investor_profile')->assertOk()->assertJsonPath('data.version', 2)->assertJsonCount(4, 'data.questions');
    $this->getJson('/api/v1/forms/membership_review')->assertNotFound();
    $this->getJson('/api/v1/forms/nope')->assertNotFound();

    // a membership officer reads the review questions but cannot publish anything
    $officer = $this->staff('membership_officer');
    $this->actingAs($officer, 'api');
    $this->getJson('/api/v1/admin/forms/membership_review')->assertOk();
    $this->getJson('/api/v1/admin/forms/investor_profile')->assertForbidden();
    $this->postJson('/api/v1/admin/forms/membership_review/publish', ['questions' => []])->assertForbidden();
});

it('rejects invalid question definitions (forward conditions, missing options, duplicate ids)', function (): void {
    $this->actingAs($this->staff('super_admin'), 'api');

    $this->postJson('/api/v1/admin/forms/opportunity/publish', ['questions' => [
        ['id' => 'q_a', 'type' => 'short_text', 'label_ar' => 'أ', 'show_when' => ['question' => 'q_b', 'equals' => 'x']],
        ['id' => 'q_b', 'type' => 'single_choice', 'label_ar' => 'ب', 'options' => [['value' => 'x', 'label_ar' => 'س']]],
        ['id' => 'q_b', 'type' => 'yes_no', 'label_ar' => 'ج', 'show_when' => ['question' => 'q_a', 'equals' => 'maybe']],
        ['id' => 'Bad Id', 'type' => 'colour', 'label_ar' => ''],
    ]])->assertStatus(422)->assertJsonValidationErrors([
        'questions.0.show_when.question', 'questions.1.options', 'questions.2.id', 'questions.3.id', 'questions.3.type', 'questions.3.label_ar',
    ]);

    // a yes/no driver only accepts "yes" / "no"
    $this->postJson('/api/v1/admin/forms/opportunity/publish', ['questions' => [
        ['id' => 'q_a', 'type' => 'yes_no', 'label_ar' => 'أ'],
        ['id' => 'q_b', 'type' => 'short_text', 'label_ar' => 'ب', 'show_when' => ['question' => 'q_a', 'equals' => 'maybe']],
    ]])->assertStatus(422)->assertJsonValidationErrors(['questions.1.show_when.equals']);

    expect(FormSchema::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- FRM-01 answers

it('validates and stores investor answers against the active schema version', function (): void {
    publishForm('investor_profile', investorQuestions());
    $investor = $this->registeredInvestor();
    $this->actingAs($investor, 'api');

    // required + conditional required + wrong types
    $this->putJson('/api/v1/investor/profile', ['extra_answers' => ['q_fund' => 'yes', 'q_focus' => ['space'], 'q_ticket' => 'كثير']])
        ->assertStatus(422)->assertJsonValidationErrors(['extra_answers.q_fund_name', 'extra_answers.q_focus', 'extra_answers.q_ticket'])
        ->assertJsonMissingValidationErrors(['extra_answers.q_retired']);
    $this->putJson('/api/v1/investor/profile', ['extra_answers' => []])->assertStatus(422)->assertJsonValidationErrors(['extra_answers.q_fund']);

    // the hidden conditional answer is dropped; values are cast; the version is stamped
    $this->putJson('/api/v1/investor/profile', ['extra_answers' => ['q_fund' => false, 'q_fund_name' => 'ignored', 'q_focus' => ['health', 'health'], 'q_ticket' => '250000', 'q_unknown' => 'x']])
        ->assertOk()->assertJsonPath('data.extra_answers.version', 1)->assertJsonPath('data.extra_answers.answers.q_fund', false);

    $stored = $investor->investorProfile->refresh()->extra_answers;
    expect($stored['key'])->toBe('investor_profile')
        ->and($stored['answers'])->toBe(['q_fund' => false, 'q_focus' => ['health'], 'q_ticket' => 250000]);

    // staff see the labelled answers in the membership file (labels from the answered version)
    $second = investorQuestions();
    $second[2]['label_ar'] = 'القطاعات';
    publishForm('investor_profile', $second);
    $this->actingAs($this->staff('membership_officer'), 'api');
    $items = $this->getJson('/api/v1/admin/memberships/'.$investor->membership->id)->assertOk()->json('data.profile.extra_answers');
    expect($items['version'])->toBe(1)->and($items['current_version'])->toBe(2)
        ->and(collect($items['items'])->firstWhere('id', 'q_focus'))->toMatchArray(['label_ar' => 'مجالات التركيز', 'choices' => [['ar' => 'الصحة', 'en' => 'Health']]]);
});

it('takes the additional answers with the registration wizard (errors under profile.extra_answers.*)', function (): void {
    Notification::fake();
    publishForm('investor_profile', investorQuestions());
    $payload = [
        'type' => 'investor_individual', 'name' => 'مستثمر جديد', 'email' => 'extra@investor.test', 'phone' => '+966512345690',
        'password' => 'StrongPass123!', 'locale' => 'ar', 'consents' => ['privacy_policy', 'terms', 'data_accuracy'],
        'profile' => ['basics' => ['city' => 'جدة'], 'extra_answers' => ['q_fund' => 'yes']],
    ];

    $this->postJson('/api/v1/auth/register', $payload)->assertStatus(422)->assertJsonValidationErrors(['profile.extra_answers.q_fund_name']);

    $payload['profile']['extra_answers']['q_fund_name'] = 'صندوق الأثر';
    $this->postJson('/api/v1/auth/register', $payload)->assertCreated();
    $profile = User::query()->where('email', 'extra@investor.test')->firstOrFail()->investorProfile;
    expect($profile->extra_answers['answers'])->toBe(['q_fund' => true, 'q_fund_name' => 'صندوق الأثر'])
        ->and($profile->city)->toBe('جدة');
});

it('blocks the membership submission until required investor questions are answered', function (): void {
    publishForm('investor_profile', investorQuestions());
    $investor = $this->registeredInvestor();
    $investor->investorProfile->forceFill(['completion_percent' => 90])->save();
    $this->actingAs($investor, 'api');

    $this->postJson('/api/v1/investor/membership/submit', ['accept_terms' => true])->assertStatus(422)->assertJsonPath('code', 'extra_answers_incomplete');

    $this->putJson('/api/v1/investor/profile', ['extra_answers' => ['q_fund' => 'no']])->assertOk();
    $investor->investorProfile->refresh()->forceFill(['completion_percent' => 90])->save();
    $this->postJson('/api/v1/investor/membership/submit', ['accept_terms' => true])->assertCreated();
    expect($investor->membership->refresh()->status)->toBe(MembershipStatus::UnderReview);
});

it('checks project answers on save and lists required ones in the submission checklist', function (): void {
    publishForm('opportunity', [
        ['id' => 'q_launch', 'type' => 'date', 'label_ar' => 'تاريخ الإطلاق', 'required' => true],
        ['id' => 'q_model', 'type' => 'single_choice', 'label_ar' => 'نموذج العمل', 'options' => [['value' => 'b2b', 'label_ar' => 'شركات'], ['value' => 'b2c', 'label_ar' => 'أفراد']]],
    ]);
    $owner = $this->entrepreneur();
    $opportunity = Opportunity::factory()->create(['owner_user_id' => $owner->id, 'status' => OpportunityStatus::Draft]);
    $this->actingAs($owner, 'api');
    $base = '/api/v1/entrepreneur/opportunities/'.$opportunity->uuid;

    // drafts: types are checked, required ones are not
    $this->patchJson($base, ['extra_answers' => ['q_launch' => '2026-02-30', 'q_model' => 'b2g']])->assertStatus(422)
        ->assertJsonValidationErrors(['extra_answers.q_launch', 'extra_answers.q_model']);
    $this->patchJson($base, ['extra_answers' => ['q_model' => 'b2b']])->assertOk()->assertJsonPath('data.extra_answers.answers.q_model', 'b2b');

    $item = collect($this->getJson($base.'/checklist')->assertOk()->json('data.items'))->firstWhere('key', 'extra_answers');
    expect($item)->toMatchArray(['section' => 'motivation', 'done' => false]);

    $this->patchJson($base, ['extra_answers' => ['q_model' => 'b2b', 'q_launch' => '2027-01-15']])->assertOk();
    expect(collect($this->getJson($base.'/checklist')->json('data.items'))->firstWhere('key', 'extra_answers')['done'])->toBeTrue()
        ->and($opportunity->refresh()->extra_answers['answers'])->toBe(['q_launch' => '2027-01-15', 'q_model' => 'b2b']);

    // an autosave with the same answers keeps the stored record (and its answered_at) untouched
    $answeredAt = $opportunity->extra_answers['answered_at'];
    $this->travel(5)->minutes();
    $this->patchJson($base, ['extra_answers' => ['q_launch' => '2027-01-15', 'q_model' => 'b2b']])->assertOk();
    expect($opportunity->refresh()->extra_answers['answered_at'])->toBe($answeredAt);

    // staff read them on the opportunity file
    $opportunity->forceFill(['status' => OpportunityStatus::New])->save();
    $this->actingAs($this->staff('super_admin'), 'api');
    $this->getJson('/api/v1/admin/opportunities/'.$opportunity->uuid)->assertOk()->assertJsonPath('data.extra_answers.items.0.id', 'q_launch');
});

it('lets membership reviewers answer the membership review questions', function (): void {
    publishForm('membership_review', [
        ['id' => 'q_interviewed', 'type' => 'yes_no', 'label_ar' => 'تمت المقابلة؟', 'required' => true],
        ['id' => 'q_notes', 'type' => 'long_text', 'label_ar' => 'ملاحظات المقابلة', 'show_when' => ['question' => 'q_interviewed', 'equals' => 'yes']],
    ]);
    $membership = $this->registeredInvestor()->membership;
    $url = '/api/v1/admin/memberships/'.$membership->id.'/review-answers';

    $this->actingAs($this->staff('reviewer'), 'api');
    $this->putJson($url, ['extra_answers' => ['q_interviewed' => true]])->assertForbidden();

    $officer = $this->staff('membership_officer');
    $this->actingAs($officer, 'api');
    $this->putJson($url, ['extra_answers' => []])->assertStatus(422)->assertJsonValidationErrors(['extra_answers.q_interviewed']);
    $this->putJson($url, ['extra_answers' => ['q_interviewed' => 'yes', 'q_notes' => 'مقابلة ممتازة']])->assertOk()
        ->assertJsonPath('data.items.1.value', 'مقابلة ممتازة');

    $this->getJson('/api/v1/admin/memberships/'.$membership->id)->assertOk()->assertJsonPath('data.extra_answers.answers.q_interviewed', true);
    $this->assertDatabaseHas('audit_logs', ['action' => 'membership.review_answers_updated', 'user_id' => $officer->id]);
});

// ---------------------------------------------------------------- UX-05 / BRD-04 preferences

it('stores the calendar and theme preferences on the user', function (): void {
    $user = $this->approvedInvestor();
    $this->actingAs($user, 'api');

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.preferences', ['calendar' => 'gregorian', 'theme' => 'system']);
    $this->patchJson('/api/v1/me/preferences', ['calendar' => 'both'])->assertOk()->assertJsonPath('data.preferences.calendar', 'both');
    $this->patchJson('/api/v1/me/preferences', ['theme' => 'dark'])->assertOk()->assertJsonPath('data.preferences', ['calendar' => 'both', 'theme' => 'dark']);
    $this->patchJson('/api/v1/me/preferences', ['calendar' => 'julian', 'theme' => 'neon'])->assertStatus(422)->assertJsonValidationErrors(['calendar', 'theme']);

    expect($user->refresh()->preferences)->toBe(['calendar' => 'both', 'theme' => 'dark']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'user.preferences_updated', 'user_id' => $user->id]);
});

// ---------------------------------------------------------------- ACC-09 identity verification

it('hides identity verification while no driver is configured', function (): void {
    $this->actingAs($this->approvedInvestor(), 'api');

    $this->getJson('/api/v1/me/identity-verification')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.verified', false);
    $this->postJson('/api/v1/me/identity-verification', ['national_id' => '1012345678'])->assertNotFound()->assertJsonPath('code', 'identity_unavailable');
});

it('verifies the national identity through the adapter (start → poll → verified)', function (): void {
    $fake = new FakeIdentityVerifier([IdentityVerificationStatus::Pending, IdentityVerificationStatus::Approved], '27');
    app()->instance(IdentityVerifier::class, $fake);
    $user = $this->approvedInvestor();
    $this->actingAs($user, 'api');

    $this->getJson('/api/v1/me/identity-verification')->assertJsonPath('data.available', true)->assertJsonPath('data.driver', 'fake');
    $this->postJson('/api/v1/me/identity-verification', ['national_id' => '٣٠١٢٣٤٥٦٧٨'])->assertStatus(422)->assertJsonValidationErrors(['national_id']);

    $started = $this->postJson('/api/v1/me/identity-verification', ['national_id' => '1012345678'])->assertCreated()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.random', '27')->json('data');
    expect($fake->started)->toBe(['1012345678']);
    $row = IdentityVerification::query()->where('uuid', $started['uuid'])->firstOrFail();
    expect($row->national_id_encrypted)->toBe('1012345678')
        ->and($row->getRawOriginal('national_id_encrypted'))->not->toContain('1012345678');
    $this->getJson('/api/v1/me/identity-verification')->assertJsonPath('data.pending.uuid', $started['uuid']);

    $this->getJson('/api/v1/me/identity-verification/'.$started['uuid'])->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.verified', false);
    $this->getJson('/api/v1/me/identity-verification/'.$started['uuid'])->assertOk()->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.verified', true)->assertJsonPath('data.random', null);

    $user->refresh();
    expect($user->national_id_verified_at)->not->toBeNull()
        ->and($user->national_id_hash)->toBe(hash_hmac('sha256', '1012345678', (string) config('app.key')))
        ->and($row->refresh()->national_id_encrypted)->toBeNull();
    $this->getJson('/api/v1/me')->assertJsonPath('data.national_id_verified_at', $user->national_id_verified_at->toIso8601String());
    $this->assertDatabaseHas('audit_logs', ['action' => 'identity.verified', 'user_id' => $user->id]);

    // already verified → refused; the same ID on another account → refused
    $this->postJson('/api/v1/me/identity-verification', ['national_id' => '1012345678'])->assertStatus(422)->assertJsonPath('code', 'identity_already_verified');
    $other = $this->approvedInvestor();
    $this->actingAs($other, 'api');
    $this->postJson('/api/v1/me/identity-verification', ['national_id' => '1012345678'])->assertStatus(422)->assertJsonPath('code', 'identity_national_id_taken');
    // another user's request is not reachable
    $this->getJson('/api/v1/me/identity-verification/'.$started['uuid'])->assertNotFound();
});

it('expires a request that was not confirmed in time and records rejections', function (): void {
    app()->instance(IdentityVerifier::class, new FakeIdentityVerifier([IdentityVerificationStatus::Rejected]));
    $user = $this->approvedInvestor();
    $this->actingAs($user, 'api');

    $first = $this->postJson('/api/v1/me/identity-verification', ['national_id' => '2012345678'])->assertCreated()->json('data.uuid');
    IdentityVerification::query()->where('uuid', $first)->update(['expires_at' => now()->subMinute()]);
    $this->getJson('/api/v1/me/identity-verification/'.$first)->assertJsonPath('data.status', 'expired');

    $second = $this->postJson('/api/v1/me/identity-verification', ['national_id' => '2012345678'])->assertCreated()->json('data.uuid');
    $this->getJson('/api/v1/me/identity-verification/'.$second)->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.verified', false);
    expect($user->refresh()->national_id_verified_at)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'identity.verification_failed', 'user_id' => $user->id]);
});

it('talks to Nafath with the configured credentials and maps its statuses', function (): void {
    Http::fake([
        'nafath.test/api/v1/mfa/request/status' => Http::sequence()->push(['status' => 'WAITING'])->push(['status' => 'COMPLETED'])->push(['status' => 'REJECTED'])->push(['status' => 'EXPIRED']),
        'nafath.test/api/v1/mfa/request*' => Http::response(['transId' => 'trx-1', 'random' => '61']),
    ]);
    $driver = new NafathIdentityVerifier('https://nafath.test', 'app-id', 'app-key', 'DigitalServiceEnrollmentWithoutBio');
    expect($driver->available())->toBeTrue()
        ->and((new NafathIdentityVerifier('', 'a', 'b', 's'))->available())->toBeFalse();

    $request = $driver->start('1012345678', 'ar');
    expect($request->transactionId)->toBe('trx-1')->and($request->random)->toBe('61')
        ->and(array_map(fn () => $driver->status('1012345678', $request), range(1, 4)))->toBe([
            IdentityVerificationStatus::Pending, IdentityVerificationStatus::Approved, IdentityVerificationStatus::Rejected, IdentityVerificationStatus::Expired,
        ]);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v1/mfa/request?local=ar') && $r->hasHeader('APP-ID', 'app-id') && $r->hasHeader('APP-KEY', 'app-key') && $r['nationalId'] === '1012345678');

    // provider failure → 502 domain error, never a raw exception
    Http::fake(['down.test/*' => Http::response(['code' => 'INVALID'], 400)]);
    $down = new NafathIdentityVerifier('https://down.test', 'app-id', 'app-key', 'DigitalServiceEnrollmentWithoutBio');
    expect(fn () => $down->start('1012345678', 'en'))->toThrow(DomainException::class);
});
