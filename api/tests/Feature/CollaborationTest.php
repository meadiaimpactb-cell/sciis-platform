<?php

declare(strict_types=1);

use App\Enums\DocumentKind;
use App\Enums\InstitutionType;
use App\Enums\InterestStatus;
use App\Enums\OpportunityStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Document;
use App\Models\Interest;
use App\Models\Nomination;
use App\Models\NotificationPreference;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * ACC-08 — multi-user organisation accounts; MTG-03 — in-platform messages tied to a record.
 * (MTG-04 / MTG-05 / NTF-04 live in CollaborationEventsTest.)
 */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    Storage::fake('local');
    config(['sciis.documents.disk' => 'local']);
});

/** An approved institutional investor (representative) with its organisation. */
function collabInvestorOrg(): User
{
    $rep = User::factory()->approvedMember()->create(['type' => UserType::InvestorInstitutional]);
    $org = Organization::query()->create(['name' => 'صندوق الأثر', 'institution_type' => InstitutionType::cases()[0], 'representative_user_id' => $rep->id]);
    $rep->forceFill(['organization_id' => $org->id])->save();

    return $rep->refresh();
}

/** An entrepreneur entity (representative) with its organisation. */
function collabEntity(): User
{
    $rep = User::factory()->create(['type' => UserType::EntrepreneurEntity]);
    $org = Organization::query()->create(['name' => 'شركة الأثر الاجتماعي', 'representative_user_id' => $rep->id]);
    $rep->forceFill(['organization_id' => $org->id])->save();

    return $rep->refresh();
}

/** A colleague already in the organisation of `$rep`. */
function collabColleague(User $rep, bool $canAct = false): User
{
    return User::factory()->create(['type' => $rep->type, 'organization_id' => $rep->organization_id, 'organization_can_act' => $canAct]);
}

/** Sends an invitation as `$rep` and returns the token from the e-mailed link. */
function collabInvite(User $rep, string $email, bool $canAct = false): string
{
    test()->actingAs($rep, 'api');
    test()->postJson('/api/v1/organization/invitations', ['email' => $email, 'name' => 'زميل جديد', 'can_act' => $canAct])->assertCreated();

    $token = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function (OrganizationInvitationNotification $n, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token): bool {
        if (! array_key_exists($email, (array) $notifiable->routes['mail'])) {
            return false;
        }
        parse_str((string) parse_url((string) $n->toMail($notifiable)->actionUrl, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;

        return true;
    });

    return (string) $token;
}

function collabAcceptPayload(array $extra = []): array
{
    return $extra + [
        'name' => 'سارة المستثمرة',
        'phone' => '0551234567',
        'password' => 'Str0ng!Passw0rd',
        'password_confirmation' => 'Str0ng!Passw0rd',
        'consents' => ['privacy_policy', 'terms', 'data_accuracy'],
    ];
}

// ====================================================================== ACC-08

it('invites a colleague by e-mail, prefills and accepts the invitation into the organisation', function (): void {
    Notification::fake();
    $rep = collabInvestorOrg();
    $token = collabInvite($rep, 'colleague@fund.sa', true);
    expect($token)->not->toBe('');

    $invitation = OrganizationInvitation::query()->where('email', 'colleague@fund.sa')->firstOrFail();
    expect($invitation->token_hash)->toBe(hash('sha256', $token))->and($invitation->expires_at->isFuture())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'organization.invitation_sent', 'auditable_id' => $invitation->id, 'user_id' => $rep->id]);

    app('auth')->forgetGuards();
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()
        ->assertJsonPath('data.email', 'colleague@fund.sa')
        ->assertJsonPath('data.organization.name', 'صندوق الأثر')
        ->assertJsonPath('data.type', 'investor_institutional');

    // Investors must declare data accuracy (INV-01).
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", collabAcceptPayload(['consents' => ['privacy_policy', 'terms']]))
        ->assertStatus(422)->assertJsonValidationErrors(['consents.data_accuracy']);

    $this->postJson("/api/v1/auth/invitations/{$token}/accept", collabAcceptPayload())->assertCreated()
        ->assertJsonPath('data.type', 'investor_institutional')
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('data.organization_access.is_representative', false)
        ->assertJsonPath('data.organization_access.can_act', true)
        ->assertJsonPath('data.membership.status', 'approved'); // the organisation's membership

    $colleague = User::query()->where('email', 'colleague@fund.sa')->firstOrFail();
    expect($colleague->organization_id)->toBe($rep->organization_id)
        ->and($colleague->organization_can_act)->toBeTrue()
        ->and($colleague->isApprovedMember())->toBeTrue()
        ->and($colleague->membership()->exists())->toBeFalse()
        ->and($invitation->refresh()->accepted_user_id)->toBe($colleague->id)
        ->and($colleague->consents()->count())->toBe(3);
    $this->assertDatabaseHas('audit_logs', ['action' => 'organization.invitation_accepted', 'auditable_id' => $invitation->id]);

    // A used link is dead.
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
});

it('refuses expired, duplicate and non-representative invitations', function (): void {
    Notification::fake();
    $rep = collabInvestorOrg();
    $token = collabInvite($rep, 'late@fund.sa');
    OrganizationInvitation::query()->where('email', 'late@fund.sa')->update(['expires_at' => now()->subDay()]);
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertStatus(410)->assertJsonPath('code', 'invitation_expired');
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", collabAcceptPayload())->assertStatus(410);

    $this->actingAs($rep, 'api');
    $this->postJson('/api/v1/organization/invitations', ['email' => 'late@fund.sa'])->assertCreated(); // expired → may re-invite
    $this->postJson('/api/v1/organization/invitations', ['email' => 'late@fund.sa'])->assertStatus(422)->assertJsonValidationErrors(['email']);
    $this->postJson('/api/v1/organization/invitations', ['email' => $rep->email])->assertStatus(422)->assertJsonValidationErrors(['email']);

    $colleague = collabColleague($rep, true);
    $this->actingAs($colleague, 'api');
    $this->postJson('/api/v1/organization/invitations', ['email' => 'x@fund.sa'])->assertForbidden()->assertJsonPath('code', 'representative_only');
    $team = $this->getJson('/api/v1/organization/team')->assertOk();
    expect($team->json('data.is_representative'))->toBeFalse()->and($team->json('data.invitations'))->toBe([])
        ->and(collect($team->json('data.members'))->pluck('email')->all())->toContain($rep->email, $colleague->email);

    // Individual accounts have no team.
    $this->actingAs($this->approvedInvestor(), 'api');
    $this->getJson('/api/v1/organization/team')->assertNotFound()->assertJsonPath('code', 'organization_required');
});

it('resends with a new link and revokes invitations', function (): void {
    Notification::fake();
    $rep = collabInvestorOrg();
    $old = collabInvite($rep, 'resend@fund.sa');
    $invitation = OrganizationInvitation::query()->where('email', 'resend@fund.sa')->firstOrFail();

    $this->postJson("/api/v1/organization/invitations/{$invitation->id}/resend")->assertOk()->assertJsonPath('data.status', 'pending');
    $this->getJson("/api/v1/auth/invitations/{$old}")->assertNotFound();

    $this->deleteJson("/api/v1/organization/invitations/{$invitation->id}")->assertOk();
    expect($invitation->refresh()->revoked_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'organization.invitation_revoked', 'auditable_id' => $invitation->id]);

    // Another organisation's representative cannot touch it.
    $this->actingAs(collabInvestorOrg(), 'api');
    $this->deleteJson("/api/v1/organization/invitations/{$invitation->id}")->assertNotFound();
});

it('shares the organisation membership and interests read-only until the representative grants "can act"', function (): void {
    $rep = collabInvestorOrg();
    $colleague = collabColleague($rep);
    $opportunity = Opportunity::factory()->eligible()->create();
    $other = Opportunity::factory()->eligible()->create();
    Interest::factory()->create(['opportunity_id' => $opportunity->id, 'investor_user_id' => $rep->id]);
    $nomination = Nomination::query()->create(['opportunity_id' => $other->id, 'investor_user_id' => $rep->id, 'match_score' => 80, 'matched_criteria' => [], 'sent_at' => now()]);

    $this->actingAs($colleague, 'api');
    $this->getJson('/api/v1/investor/interests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.opportunity.uuid', $opportunity->uuid);
    $this->getJson('/api/v1/investor/nominations')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/opportunities/{$other->uuid}")->assertOk(); // nominated to the organisation (nomination-only policy)

    // Read-only: no interest, no nomination answer.
    $this->postJson("/api/v1/opportunities/{$other->uuid}/interest", ['note' => 'نود الاطلاع'])->assertForbidden();
    $this->postJson("/api/v1/investor/nominations/{$nomination->id}/respond", ['response' => 'interested'])->assertForbidden();

    $this->actingAs($rep, 'api');
    $this->patchJson("/api/v1/organization/members/{$colleague->id}", ['can_act' => true])->assertOk()->assertJsonPath('data.can_act', true);
    $this->assertDatabaseHas('audit_logs', ['action' => 'organization.member_updated', 'auditable_id' => $colleague->id, 'user_id' => $rep->id]);
    $this->patchJson("/api/v1/organization/members/{$rep->id}", ['can_act' => false])->assertStatus(422);

    $this->actingAs($colleague->refresh(), 'api');
    $this->postJson("/api/v1/opportunities/{$other->uuid}/interest", ['note' => 'نود الاطلاع'])->assertCreated();
});

it('disables and re-enables a colleague without lifting a staff suspension', function (): void {
    $rep = collabInvestorOrg();
    $colleague = collabColleague($rep);
    $colleague->createToken('mobile');

    $this->actingAs($rep, 'api');
    $this->patchJson("/api/v1/organization/members/{$colleague->id}", ['disabled' => true])->assertOk()->assertJsonPath('data.status', 'disabled');
    $colleague->refresh();
    expect($colleague->status)->toBe(UserStatus::Suspended)->and($colleague->organization_disabled_at)->not->toBeNull()->and($colleague->tokens()->count())->toBe(0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'organization.member_disabled', 'auditable_id' => $colleague->id]);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => $colleague->email, 'password' => 'Password123!'])->assertForbidden()->assertJsonPath('code', 'account_suspended');

    $this->actingAs($rep, 'api');
    $this->patchJson("/api/v1/organization/members/{$colleague->id}", ['disabled' => false])->assertOk()->assertJsonPath('data.status', 'active');
    expect($colleague->refresh()->status)->toBe(UserStatus::Active);

    // Suspended by the council: the representative cannot bring the account back.
    $colleague->forceFill(['status' => UserStatus::Suspended])->save();
    $this->patchJson("/api/v1/organization/members/{$colleague->id}", ['disabled' => false])->assertOk();
    expect($colleague->refresh()->status)->toBe(UserStatus::Suspended);

    // Outsiders are not members.
    $this->patchJson('/api/v1/organization/members/'.$this->approvedInvestor()->id, ['can_act' => true])->assertNotFound();
});

it('transfers the representative role and keeps the membership with the organisation', function (): void {
    Notification::fake();
    $rep = collabInvestorOrg();
    $colleague = collabColleague($rep);

    $this->actingAs($rep, 'api');
    $this->postJson('/api/v1/organization/representative', ['user_id' => $colleague->id])->assertOk()
        ->assertJsonPath('data.is_representative', false)
        ->assertJsonPath('data.can_act', true);
    expect(Organization::query()->find($rep->organization_id)->representative_user_id)->toBe($colleague->id)
        ->and($rep->refresh()->organization_can_act)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'organization.representative_transferred', 'user_id' => $rep->id]);

    $this->postJson('/api/v1/organization/invitations', ['email' => 'new@fund.sa'])->assertForbidden();

    $newRep = $colleague->refresh();
    expect($newRep->isOrganizationRepresentative())->toBeTrue()->and($newRep->isApprovedMember())->toBeTrue();
    $this->actingAs($newRep, 'api');
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.organization_access.is_representative', true)->assertJsonPath('data.membership.status', 'approved');
    $this->postJson('/api/v1/organization/invitations', ['email' => 'new@fund.sa'])->assertCreated();
});

it('lets entity colleagues see the files and act on them only with "can act"', function (): void {
    $rep = collabEntity();
    $reader = collabColleague($rep);
    $actor = collabColleague($rep, true);
    $opportunity = Opportunity::factory()->create(['owner_user_id' => $rep->id, 'organization_id' => $rep->organization_id, 'status' => OpportunityStatus::Draft]);

    $this->actingAs($reader, 'api');
    $this->getJson('/api/v1/entrepreneur/opportunities')->assertOk()->assertJsonPath('data.0.uuid', $opportunity->uuid);
    $this->getJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}")->assertOk();
    expect($reader->can('update', $opportunity))->toBeFalse()->and($reader->can('submit', $opportunity))->toBeFalse()
        ->and($actor->can('update', $opportunity))->toBeTrue()
        ->and($this->entrepreneur()->can('view', $opportunity))->toBeFalse();
});

// ====================================================================== MTG-03

it('threads council ↔ owner messages on the file with unread counts and notifications', function (): void {
    Notification::fake();
    $owner = $this->entrepreneur();
    $reviewer = $this->staff('reviewer');
    $opportunity = Opportunity::factory()->create(['owner_user_id' => $owner->id, 'status' => OpportunityStatus::UnderReview, 'submitted_at' => now()]);

    $this->actingAs($reviewer, 'api');
    $this->getJson("/api/v1/admin/messages/opportunity/{$opportunity->uuid}")->assertOk()->assertJsonPath('data.thread', null)->assertJsonPath('data.can_post', true);
    $this->postJson("/api/v1/admin/messages/opportunity/{$opportunity->uuid}", ['body' => 'نرجو تحديث القوائم المالية.'])->assertCreated()
        ->assertJsonPath('data.author.side', 'staff')->assertJsonPath('data.author.is_me', true);
    Notification::assertSentTo($owner, MessageReceivedNotification::class, fn ($n, array $channels): bool => in_array('database', $channels, true) && in_array('mail', $channels, true));

    $this->actingAs($owner, 'api');
    $this->getJson('/api/v1/messages/unread-count')->assertOk()->assertJsonPath('data.count', 1);
    $this->getJson('/api/v1/messages')->assertOk()->assertJsonPath('data.0.unread_count', 1)->assertJsonPath('data.0.subject.type', 'opportunity')
        ->assertJsonPath('data.0.subject.key', $opportunity->uuid)->assertJsonPath('meta.unread_total', 1);
    $this->getJson("/api/v1/messages/opportunity/{$opportunity->uuid}")->assertOk()->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.messages.0.body', 'نرجو تحديث القوائم المالية.')->assertJsonPath('data.can_post', true);
    $this->getJson('/api/v1/messages/unread-count')->assertJsonPath('data.count', 0);

    $this->postJson("/api/v1/messages/opportunity/{$opportunity->uuid}", ['body' => 'تم التحديث، شكرًا.'])->assertCreated();
    Notification::assertSentTo($reviewer, MessageReceivedNotification::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'message.sent', 'user_id' => $owner->id]);

    $this->actingAs($reviewer, 'api');
    $this->getJson('/api/v1/admin/messages/unread-count')->assertJsonPath('data.count', 1);
    $this->getJson('/api/v1/admin/messages?unread=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject.counterpart', $owner->name);

    // Empty messages are refused; staff use the admin routes only, parties the party routes only.
    $this->postJson("/api/v1/admin/messages/opportunity/{$opportunity->uuid}", ['body' => ''])->assertStatus(422);
    $this->getJson('/api/v1/messages/unread-count')->assertForbidden();
    $this->actingAs($owner, 'api');
    $this->getJson('/api/v1/admin/messages/unread-count')->assertForbidden();
});

it('keeps investor threads away from the owner (MSG-01) and attachments in the secure pipeline', function (): void {
    Notification::fake();
    $owner = $this->entrepreneur();
    $investor = $this->approvedInvestor();
    $officer = $this->staff('matching_officer');
    $opportunity = Opportunity::factory()->eligible()->create(['owner_user_id' => $owner->id]);
    $interest = Interest::factory()->create(['opportunity_id' => $opportunity->id, 'investor_user_id' => $investor->id, 'status' => InterestStatus::AwaitingOwnerApproval]);

    $this->actingAs($investor, 'api');
    $this->getJson("/api/v1/messages/opportunity/{$opportunity->uuid}")->assertForbidden();
    $uuid = $this->post("/api/v1/messages/interest/{$interest->id}", [
        'body' => 'مرفق خطاب الاهتمام.',
        'files' => [UploadedFile::fake()->createWithContent('letter.pdf', "%PDF-1.4\nletter")],
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.attachments.0.uuid');

    $document = Document::query()->where('uuid', $uuid)->firstOrFail();
    expect($document->kind)->toBe(DocumentKind::MessageAttachment)->and($document->documentable_type)->toBe('thread_message')->and($document->encrypted)->toBeTrue();
    Notification::assertSentTo($officer, MessageReceivedNotification::class);
    Notification::assertNotSentTo($owner, MessageReceivedNotification::class);

    $this->actingAs($officer, 'api');
    $this->getJson("/api/v1/admin/messages/attachments/{$uuid}/download-url")->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']]);
    $this->getJson("/api/v1/admin/messages/interest/{$interest->id}")->assertOk()->assertJsonPath('data.subject.counterpart', $investor->name);

    $this->actingAs($owner, 'api');
    $this->getJson("/api/v1/messages/interest/{$interest->id}")->assertForbidden();
    $this->getJson("/api/v1/messages/attachments/{$uuid}/download-url")->assertForbidden();
    $this->getJson('/api/v1/messages')->assertOk()->assertJsonCount(0, 'data');
    expect(Gate::forUser($owner)->allows('download', $document))->toBeFalse();

    // A reviewer without nominations.manage does not read investor correspondence.
    $this->actingAs($this->staff('reviewer'), 'api');
    $this->getJson("/api/v1/admin/messages/interest/{$interest->id}")->assertForbidden();
});

it('lets read-only colleagues follow investor threads without posting', function (): void {
    Notification::fake();
    $rep = collabInvestorOrg();
    $reader = collabColleague($rep);
    $nomination = Nomination::query()->create(['opportunity_id' => Opportunity::factory()->eligible()->create()->id, 'investor_user_id' => $rep->id, 'match_score' => 70, 'matched_criteria' => [], 'sent_at' => now()]);
    $officer = $this->staff('matching_officer');

    $this->actingAs($officer, 'api');
    $this->postJson("/api/v1/admin/messages/nomination/{$nomination->id}", ['body' => 'هل لديكم أسئلة عن الترشيح؟'])->assertCreated();
    Notification::assertSentTo([$rep, $reader], MessageReceivedNotification::class);

    $this->actingAs($reader, 'api');
    $this->getJson("/api/v1/messages/nomination/{$nomination->id}")->assertOk()->assertJsonPath('data.can_post', false)->assertJsonCount(1, 'data.messages');
    $this->postJson("/api/v1/messages/nomination/{$nomination->id}", ['body' => 'رد'])->assertForbidden();

    // Mail follows the recipient's preferences (category `message`).
    NotificationPreference::query()->create(['user_id' => $rep->id, 'channel' => 'mail', 'category' => 'message', 'enabled' => false]);
    $this->actingAs($officer, 'api');
    $this->postJson("/api/v1/admin/messages/nomination/{$nomination->id}", ['body' => 'تذكير'])->assertCreated();
    Notification::assertSentTo($rep, MessageReceivedNotification::class, fn ($n, array $channels): bool => in_array('database', $channels, true) && ! in_array('mail', $channels, true) && ! in_array('whatsapp', $channels, true));
});
