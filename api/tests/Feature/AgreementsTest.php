<?php

declare(strict_types=1);

use App\Enums\AgreementParty;
use App\Enums\AgreementStatus;
use App\Enums\InterestStatus;
use App\Enums\OpportunityStatus;
use App\Models\Agreement;
use App\Models\AgreementSignature;
use App\Models\AgreementTemplate;
use App\Models\AgreementVersion;
use App\Models\Interest;
use App\Models\Opportunity;
use App\Notifications\AgreementNotification;
use App\Services\Agreements\LineDiff;
use App\Services\Agreements\Signatures\SignatureOutcome;
use App\Services\Agreements\Signatures\SignatureProvider;
use App\Services\Agreements\Signatures\SignatureRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** AGR-01..05 — templates, drafting per connection, versions + compare, legal review, party access, e-signature, archive. */
beforeEach(function (): void {
    Storage::fake('local');
    config(['sciis.documents.disk' => 'local']);

    $this->officer = $this->staff('agreements_officer');
    $this->approver = $this->staff('super_admin');
    $this->owner = $this->entrepreneur();
    $this->investor = $this->approvedInvestor();

    $this->opportunity = Opportunity::factory()->eligible()->create(['owner_user_id' => $this->owner->id, 'funding_amount' => 750000, 'name' => 'مشروع الأثر']);
    $this->opportunity->forceFill(['status' => OpportunityStatus::Connected])->save();
    $this->interest = Interest::factory()->create([
        'opportunity_id' => $this->opportunity->id, 'investor_user_id' => $this->investor->id, 'status' => InterestStatus::Connected,
    ]);

    $this->template = AgreementTemplate::query()->create([
        'key' => 'test_investment', 'version' => 1, 'is_active' => true, 'legal_approved_at' => now(),
        'title' => 'اتفاقية اختبار',
        'title_en' => 'Test agreement',
        'body' => "# اتفاقية\nالمستثمر: {investor_name}\nالمشروع: {project_name}\nالمبلغ: {amount}\nالمدينة: {city}\nالتاريخ: {date}",
        'body_en' => "# Agreement\nInvestor: {investor_name}\nProject: {project_name}\nAmount: {amount}",
    ]);
});

/** Drafts an agreement as the officer and returns it. */
function draftAgreement(object $t): Agreement
{
    $t->actingAs($t->officer, 'api');
    $uuid = $t->postJson('/api/v1/admin/agreements', [
        'interest_id' => $t->interest->id, 'template_id' => $t->template->id, 'placeholders' => ['city' => 'الرياض'],
    ])->assertCreated()->json('data.uuid');

    return Agreement::query()->where('uuid', $uuid)->firstOrFail();
}

/** Draft → review → approved (by the super admin). */
function approvedAgreement(object $t, ?Agreement $agreement = null): Agreement
{
    $agreement ??= draftAgreement($t);
    $t->actingAs($t->officer, 'api');
    $t->postJson("/api/v1/admin/agreements/{$agreement->uuid}/submit-review")->assertOk();
    $t->actingAs($t->approver, 'api');
    $t->postJson("/api/v1/admin/agreements/{$agreement->uuid}/review", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');

    return $agreement->refresh();
}

function signPayload(Agreement $agreement): array
{
    $version = $agreement->refresh()->currentVersion;

    return ['consent' => true, 'version' => $version->version, 'text_hash' => $version->text_hash];
}

it('manages versioned templates: create, new version, single active version per key (AGR-01)', function (): void {
    $this->actingAs($this->officer, 'api');

    $id = $this->postJson('/api/v1/admin/agreement-templates', [
        'key' => 'loan', 'title' => 'اتفاقية قرض حسن', 'title_en' => 'Benevolent loan', 'body' => 'نص الاتفاقية مع {investor_name} و{project_name}.',
    ])->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.placeholders', ['investor_name', 'project_name'])->json('data.id');

    $this->postJson('/api/v1/admin/agreement-templates', ['key' => 'loan', 'title' => 'مكرر', 'body' => 'نص آخر طويل بما يكفي للتحقق.'])
        ->assertUnprocessable()->assertJsonValidationErrors(['key']);

    $this->postJson("/api/v1/admin/agreement-templates/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
    $v2 = $this->postJson("/api/v1/admin/agreement-templates/{$id}/versions", [
        'title' => 'اتفاقية قرض حسن', 'body' => 'نص معدّل للاتفاقية مع {investor_name}.', 'change_note' => 'تحديث البند الأول',
    ])->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.is_active', false)->json('data.id');

    $this->postJson("/api/v1/admin/agreement-templates/{$v2}/activate")->assertOk();
    expect(AgreementTemplate::query()->find($id)->is_active)->toBeFalse()
        ->and(AgreementTemplate::query()->find($v2)->is_active)->toBeTrue();

    $family = collect($this->getJson('/api/v1/admin/agreement-templates')->assertOk()->json('data.templates'))->firstWhere('key', 'loan');
    expect($family['versions'])->toHaveCount(2)->and($family['active']['version'])->toBe(2);
    $this->assertDatabaseHas('audit_logs', ['action' => 'agreement_template.activated', 'auditable_id' => $v2]);

    // The bilingual starting template ships inactive, pending legal approval.
    $seeded = AgreementTemplate::query()->where('key', 'social_investment')->firstOrFail();
    expect($seeded->is_active)->toBeFalse()->and($seeded->body_en)->toContain('{investor_name}');

    $this->actingAs($this->staff('evaluator'), 'api');
    $this->getJson('/api/v1/admin/agreement-templates')->assertForbidden();
});

it('drafts an agreement for a connection from an active template, filling placeholders (AGR-02)', function (): void {
    $this->actingAs($this->officer, 'api');

    $connections = $this->getJson('/api/v1/admin/agreements/connections')->assertOk()->json('data.connections');
    expect(collect($connections)->pluck('interest_id'))->toContain($this->interest->id);

    $preview = collect($this->getJson("/api/v1/admin/agreements/draft-preview?interest_id={$this->interest->id}&template_id={$this->template->id}")
        ->assertOk()->json('data.placeholders'))->keyBy('key');
    expect($preview['amount']['value'])->toBe('750,000')->and($preview['city']['value'])->toBe('')->and($preview['city']['auto'])->toBeFalse();

    $agreement = draftAgreement($this);
    $version = $agreement->currentVersion;
    expect($agreement->status)->toBe(AgreementStatus::Draft)
        ->and($version->version)->toBe(1)
        ->and($version->body)->toContain($this->investor->name)->toContain('مشروع الأثر')->toContain('750,000')->toContain('الرياض')
        ->and($version->body_en)->toContain('Amount: 750,000')
        ->and($version->text_hash)->toBe(hash('sha256', $version->body."\n\n".$version->body_en));
    $this->assertDatabaseHas('audit_logs', ['action' => 'agreement.created', 'auditable_type' => 'agreement', 'auditable_id' => $agreement->id]);

    // One agreement per connection; the connection leaves the "ready" list.
    $this->postJson('/api/v1/admin/agreements', ['interest_id' => $this->interest->id, 'template_id' => $this->template->id])
        ->assertUnprocessable()->assertJsonPath('code', 'agreement_exists');
    expect(collect($this->getJson('/api/v1/admin/agreements/connections')->json('data.connections'))->pluck('interest_id'))->not->toContain($this->interest->id);
});

it('refuses drafting before the connection or from an inactive template', function (): void {
    $this->actingAs($this->officer, 'api');
    $this->interest->forceFill(['status' => InterestStatus::Shared])->save();
    $this->postJson('/api/v1/admin/agreements', ['interest_id' => $this->interest->id, 'template_id' => $this->template->id])
        ->assertUnprocessable()->assertJsonPath('code', 'agreement_not_connected');

    $this->interest->forceFill(['status' => InterestStatus::Connected])->save();
    $this->template->forceFill(['is_active' => false])->save();
    $this->postJson('/api/v1/admin/agreements', ['interest_id' => $this->interest->id, 'template_id' => $this->template->id])
        ->assertUnprocessable()->assertJsonPath('code', 'agreement_template_inactive');

    $this->actingAs($this->staff('matching_officer'), 'api');
    $this->getJson('/api/v1/admin/agreements')->assertForbidden();
});

it('creates a new version on every edit and compares versions line by line (AGR-02)', function (): void {
    $agreement = draftAgreement($this);
    $v1 = $agreement->currentVersion;
    $body = str_replace('المدينة: الرياض', "المدينة: جدة\nبند إضافي: مدة الاتفاقية سنتان", $v1->body);

    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/versions", ['body' => $body, 'body_en' => $v1->body_en, 'change_note' => 'تعديل المدينة وإضافة المدة'])
        ->assertCreated()->assertJsonPath('data.current_version.version', 2)->assertJsonCount(2, 'data.versions');
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/versions", ['body' => $body, 'body_en' => $v1->body_en, 'change_note' => 'بلا تغيير'])
        ->assertUnprocessable()->assertJsonPath('code', 'agreement_no_changes');

    $diff = $this->getJson("/api/v1/admin/agreements/{$agreement->uuid}/compare?from=1&to=2")->assertOk()->json('data');
    $changed = collect($diff['body']['lines'])->where('type', '!=', 'same')->values();
    expect($diff['body']['added'])->toBe(2)->and($diff['body']['removed'])->toBe(1)->and($diff['body_en']['added'])->toBe(0)
        ->and($changed->firstWhere('type', 'removed')['text'])->toBe('المدينة: الرياض')
        ->and($changed->where('type', 'added')->pluck('text')->all())->toBe(['المدينة: جدة', 'بند إضافي: مدة الاتفاقية سنتان']);

    expect(fn () => $v1->refresh()->forceFill(['body' => 'x'])->save())->toThrow(LogicException::class);
});

it('computes a minimal line diff', function (): void {
    $diff = LineDiff::compare("a\nb\nc\nd", "a\nc\nd\ne");
    expect(collect($diff['lines'])->map(fn ($l) => ($l['type'] === 'same' ? ' ' : ($l['type'] === 'added' ? '+' : '-')).$l['text'])->all())
        ->toBe([' a', '-b', ' c', ' d', '+e']);
    expect(LineDiff::compare('', "x\ny")['added'])->toBe(2);
});

it('runs the legal review with four-eyes approval and notifies reviewers and drafters (AGR-03)', function (): void {
    Notification::fake();
    $agreement = draftAgreement($this);

    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/submit-review", ['note' => 'جاهزة للمراجعة'])->assertOk()->assertJsonPath('data.status', 'legal_review');
    Notification::assertSentTo($this->approver, AgreementNotification::class, fn ($n) => $n->toArray($this->approver)['type'] === 'agreement.review_requested');

    // agreements.manage alone cannot approve, but can request changes (with a comment).
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/review", ['decision' => 'approved'])->assertForbidden();
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/review", ['decision' => 'changes_requested'])->assertUnprocessable()->assertJsonValidationErrors(['note']);

    $this->actingAs($this->approver, 'api');
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/review", ['decision' => 'changes_requested', 'note' => 'عدّل بند المدة'])
        ->assertOk()->assertJsonPath('data.status', 'changes_requested')->assertJsonPath('data.reviews.0.note', 'عدّل بند المدة');
    Notification::assertSentTo($this->officer, AgreementNotification::class, fn ($n) => $n->toArray($this->officer)['type'] === 'agreement.changes_requested');

    // The reviewer revises the text himself → he may not approve his own version (four-eyes); another reviewer may.
    $body = $agreement->refresh()->currentVersion->body."\nبند المدة: سنتان";
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/versions", ['body' => $body, 'change_note' => 'إضافة بند المدة'])
        ->assertCreated()->assertJsonPath('data.status', 'changes_requested');
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/submit-review")->assertOk();
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/review", ['decision' => 'approved'])->assertForbidden()->assertJsonPath('code', 'agreement_self_approval');

    $this->actingAs($this->staff('super_admin'), 'api');
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/review", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
});

it('lets a different reviewer approve and records the approval on the version (AGR-03)', function (): void {
    Notification::fake();
    $agreement = draftAgreement($this);
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/submit-review")->assertOk();

    // The author cannot approve his own version even with agreements.approve.
    $this->officer->givePermissionTo('agreements.approve');
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/review", ['decision' => 'approved'])->assertForbidden()->assertJsonPath('code', 'agreement_self_approval');

    $this->actingAs($this->approver, 'api');
    $data = $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/review", ['decision' => 'approved', 'note' => 'سليمة'])->assertOk()->json('data');
    expect($data['status'])->toBe('approved')->and($data['versions'][0]['is_approved'])->toBeTrue()->and($data['allowed_actions'])->toContain('send_for_signature');
    Notification::assertSentTo($this->officer, AgreementNotification::class, fn ($n) => $n->toArray($this->officer)['type'] === 'agreement.approved');
    $this->assertDatabaseHas('agreement_reviews', ['version_id' => $agreement->current_version_id, 'reviewer_id' => $this->approver->id, 'decision' => 'approved']);
});

it('shows parties only their approved agreements, read-only and without internal review notes (AGR-04)', function (): void {
    $agreement = draftAgreement($this);

    $this->actingAs($this->owner, 'api');
    $this->getJson('/api/v1/entrepreneur/agreements')->assertOk()->assertJsonCount(0, 'data.agreements');
    $this->getJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}")->assertForbidden();

    $agreement = approvedAgreement($this, $agreement);

    $this->actingAs($this->owner, 'api');
    $this->getJson('/api/v1/entrepreneur/agreements')->assertOk()->assertJsonCount(1, 'data.agreements')->assertJsonPath('data.agreements.0.my_party', 'owner');
    $data = $this->getJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}")->assertOk()->json('data');
    expect($data['my_party'])->toBe('owner')->and($data['version']['version'])->toBe(1)->and($data['can_sign'])->toBeFalse()
        ->and($data)->not->toHaveKeys(['reviews', 'audit', 'allowed_actions'])
        ->and($data['version'])->not->toHaveKey('change_note');
    $this->assertDatabaseHas('audit_logs', ['action' => 'agreement.viewed', 'auditable_id' => $agreement->id, 'user_id' => $this->owner->id]);

    // The investor sees it in his portal, not through the owner's.
    $this->actingAs($this->investor, 'api');
    $this->getJson("/api/v1/investor/agreements/{$agreement->uuid}")->assertOk()->assertJsonPath('data.my_party', 'investor');
    $this->getJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}")->assertForbidden();

    $this->actingAs($this->approvedInvestor(), 'api');
    $this->getJson("/api/v1/investor/agreements/{$agreement->uuid}")->assertForbidden();
    $this->getJson('/api/v1/investor/agreements')->assertOk()->assertJsonCount(0, 'data.agreements');
});

it('collects both e-signatures with consent, text hash, IP and user agent, then locks the agreement (AGR-05)', function (): void {
    Notification::fake();
    $agreement = approvedAgreement($this);

    // Not open for signature yet.
    $this->actingAs($this->owner, 'api');
    $this->postJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}/sign", signPayload($agreement))->assertUnprocessable()->assertJsonPath('code', 'agreement_not_open_for_signature');

    $this->actingAs($this->officer, 'api');
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/send-for-signature")->assertOk()->assertJsonPath('data.status', 'sent_for_signature');
    Notification::assertSentTo([$this->owner, $this->investor], AgreementNotification::class, fn ($n, $channels, $u) => $n->toArray($u)['type'] === 'agreement.sent_for_signature');

    $this->actingAs($this->owner, 'api');
    expect($this->getJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}")->json('data.can_sign'))->toBeTrue()
        ->and($this->getJson('/api/v1/entrepreneur/agreements')->json('data.agreements.0.awaiting_my_signature'))->toBeTrue();
    $this->postJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}/sign", ['version' => 1, 'text_hash' => $agreement->currentVersion->text_hash])
        ->assertUnprocessable()->assertJsonValidationErrors(['consent']);
    $this->postJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}/sign", ['consent' => true, 'version' => 1, 'text_hash' => str_repeat('a', 64)])
        ->assertStatus(409)->assertJsonPath('code', 'agreement_text_changed');

    $this->withHeaders(['User-Agent' => 'PestBrowser/1.0'])
        ->postJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}/sign", signPayload($agreement))
        ->assertOk()->assertJsonPath('data.status', 'sent_for_signature')->assertJsonPath('data.parties_status.owner.signed', true)->assertJsonPath('data.can_sign', false);
    $this->postJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}/sign", signPayload($agreement))->assertUnprocessable()->assertJsonPath('code', 'agreement_already_signed');

    $this->actingAs($this->investor, 'api');
    $this->postJson("/api/v1/investor/agreements/{$agreement->uuid}/sign", signPayload($agreement))->assertOk()->assertJsonPath('data.status', 'signed');

    $agreement->refresh();
    $owner = AgreementSignature::query()->where('agreement_id', $agreement->id)->where('party', AgreementParty::Owner->value)->firstOrFail();
    expect($agreement->status)->toBe(AgreementStatus::Signed)->and($agreement->signature_method)->toBe('electronic')->and($agreement->signature_provider)->toBe('simple')
        ->and($owner->user_id)->toBe($this->owner->id)->and($owner->text_hash)->toBe($agreement->currentVersion->text_hash)
        ->and($owner->user_agent)->toBe('PestBrowser/1.0')->and($owner->ip)->not->toBeNull()->and($owner->consent_text)->toContain($agreement->number());
    expect(fn () => $owner->update(['ip' => '1.1.1.1']))->toThrow(LogicException::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'agreement.signed', 'auditable_id' => $agreement->id]);
    Notification::assertSentTo([$this->owner, $this->investor, $this->officer], AgreementNotification::class, fn ($n, $channels, $u) => $n->toArray($u)['type'] === 'agreement.signed');

    // Signed = read-only: no new version; then archived.
    $this->actingAs($this->officer, 'api');
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/versions", ['body' => 'نص جديد طويل بما يكفي للاختبار', 'change_note' => 'محاولة'])
        ->assertUnprocessable()->assertJsonPath('code', 'agreement_not_editable');
    $data = $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/archive")->assertOk()->json('data');
    expect($data['status'])->toBe('archived')->and($data['signatures'])->toHaveCount(2)->and($data['signatures'][0]['ip'])->not->toBeNull()
        ->and(collect($data['audit'])->pluck('action'))->toContain('agreement.signed_by_party', 'agreement.sent_for_signature', 'agreement.approved', 'agreement.archived');
});

it('returns an approved agreement to draft when its text is edited, voiding pending signatures', function (): void {
    $agreement = approvedAgreement($this);
    $this->actingAs($this->officer, 'api');
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/send-for-signature")->assertOk();
    $this->actingAs($this->owner, 'api');
    $this->postJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}/sign", signPayload($agreement))->assertOk();

    $this->actingAs($this->officer, 'api');
    $body = $agreement->refresh()->currentVersion->body."\nبند جديد";
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/versions", ['body' => $body, 'change_note' => 'بند جديد بعد الاعتماد'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.parties_status.owner.signed', false);

    // Parties keep seeing the last approved version (v1) while v2 is reviewed.
    $this->actingAs($this->investor, 'api');
    $this->getJson("/api/v1/investor/agreements/{$agreement->uuid}")->assertOk()->assertJsonPath('data.version.version', 1)->assertJsonPath('data.can_sign', false);
});

it('accepts a wet-signed PDF as the signed copy and lets the parties download it (AGR-05)', function (): void {
    $agreement = approvedAgreement($this);
    $this->actingAs($this->officer, 'api');

    $this->post("/api/v1/admin/agreements/{$agreement->uuid}/signed-copy", ['file' => UploadedFile::fake()->create('scan.png', 20, 'image/png')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['file']);
    $this->post("/api/v1/admin/agreements/{$agreement->uuid}/signed-copy", ['file' => UploadedFile::fake()->create('signed.pdf', 40, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.status', 'signed')->assertJsonPath('data.signature_method', 'wet');

    $agreement->refresh();
    expect($agreement->signedDocument)->not->toBeNull()->and($agreement->signedDocument->documentable_type)->toBe('agreement');

    $this->actingAs($this->investor, 'api');
    $this->getJson("/api/v1/investor/agreements/{$agreement->uuid}/signed-copy/download-url")->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']]);
    $this->assertDatabaseHas('document_access_logs', ['document_id' => $agreement->signed_document_id, 'user_id' => $this->investor->id]);
});

it('cancels an open agreement with a reason and frees the connection', function (): void {
    $agreement = draftAgreement($this);
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/cancel")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/cancel", ['reason' => 'انسحب المستثمر'])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.allowed_actions', []);

    $list = $this->getJson('/api/v1/admin/agreements?status=cancelled')->assertOk();
    expect($list->json('meta.total'))->toBe(1)->and($list->json('meta.counts.cancelled'))->toBe(1);
    expect(collect($this->getJson('/api/v1/admin/agreements/connections')->json('data.connections'))->pluck('interest_id'))->toContain($this->interest->id);
});

it('signs through the configured SignatureProvider driver (pending drivers return a redirect URL)', function (): void {
    $agreement = approvedAgreement($this);
    $this->actingAs($this->officer, 'api');
    $this->postJson("/api/v1/admin/agreements/{$agreement->uuid}/send-for-signature")->assertOk();

    app()->bind(SignatureProvider::class, fn () => new class implements SignatureProvider
    {
        public function key(): string
        {
            return 'licensed_test';
        }

        public function request(SignatureRequest $request): SignatureOutcome
        {
            return SignatureOutcome::pending('https://sign.example.test/session/'.$request->party->value);
        }
    });

    $this->actingAs($this->owner, 'api');
    $this->postJson("/api/v1/entrepreneur/agreements/{$agreement->uuid}/sign", signPayload($agreement))
        ->assertOk()->assertJsonPath('data.redirect_url', 'https://sign.example.test/session/owner');
    expect(AgreementSignature::query()->count())->toBe(0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'agreement.signature_requested', 'auditable_id' => $agreement->id]);
});

it('stores immutable versions whose hash covers the exact text', function (): void {
    $agreement = draftAgreement($this);
    $version = $agreement->currentVersion;
    expect($version->text_hash)->toBe(AgreementVersion::hashText($version->body, $version->body_en))->and(strlen($version->text_hash))->toBe(64);
});

it('renders the agreement notifications in both languages with portal deep links', function (): void {
    $agreement = draftAgreement($this);
    foreach (AgreementNotification::EVENTS as $event) {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $n = new AgreementNotification($agreement, $event, 'ملاحظة');
            $mail = $n->toMail($this->owner);
            expect($mail->subject)->toContain($agreement->number())->not->toContain('notifications.')
                ->and($mail->actionUrl)->toEndWith("/app/entrepreneur/agreements/{$agreement->uuid}");
        }
    }
    app()->setLocale('ar');
    expect((new AgreementNotification($agreement, 'sent_for_signature'))->toSms($this->investor))->toContain($agreement->number())
        ->and((new AgreementNotification($agreement, 'approved'))->toSms($this->investor))->toBeNull()
        ->and((new AgreementNotification($agreement, 'review_requested'))->toArray($this->approver)['link'])->toBe("/app/admin/agreements/{$agreement->uuid}");
});
