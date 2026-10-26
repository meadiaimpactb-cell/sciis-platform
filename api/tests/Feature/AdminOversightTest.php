<?php

declare(strict_types=1);

use App\Enums\MeetingStatus;
use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Interest;
use App\Models\Meeting;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MembershipService;
use App\Services\OpportunityStateMachine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin oversight: MAT-01 search & filters, ADM-06 merged timeline + user file, AUD-01 read-access logging,
 * AUD-02 DB-level immutability, AUD-03 date range / ip, ACC-04 denied-access logging, ADM-07 approval matrix +
 * supervisor sign-off for not_eligible.
 */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->admin = $this->staff('super_admin');
});

function oversightTerm(string $group, string $key): TaxonomyTerm
{
    return TaxonomyTerm::query()->where('group', $group)->where('key', $key)->firstOrFail();
}

// ───────────────────────────────────────────────────────────── MAT-01

it('filters the users list by search, role and status', function (): void {
    $reviewer = User::factory()->staff('reviewer')->create(['name' => 'ريم المراجعة', 'email' => 'reem.reviewer@sciis.test']);
    User::factory()->staff('evaluator')->create(['status' => 'suspended']);
    $this->actingAs($this->admin, 'api');

    expect($this->getJson('/api/v1/admin/users?filter[search]=reem.reviewer')->assertOk()->json('data.0.id'))->toBe($reviewer->id)
        ->and($this->getJson('/api/v1/admin/users?filter[role]=reviewer')->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/admin/users?filter[type]=staff&filter[status]=suspended')->assertOk()->json('meta.total'))->toBe(1);
});

it('browses investors with multi-criteria filters on the memberships list', function (): void {
    $education = oversightTerm('sectors', 'education');
    $riyadh = oversightTerm('regions', 'riyadh');
    $seed = oversightTerm('project_stages', 'seed');
    $equity = oversightTerm('investment_types', 'equity');
    $r2 = oversightTerm('investment_ranges', 'r2');

    $match = $this->approvedInvestor();
    $match->investorProfile->update(['investment_range_id' => $r2->id]);
    $match->investorProfile->sectors()->sync([$education->id]);
    $match->investorProfile->regions()->sync([$riyadh->id]);
    $match->investorProfile->stages()->sync([$seed->id]);
    $match->investorProfile->investmentTypes()->sync([$equity->id]);

    $institution = User::factory()->investor()->create(['type' => UserType::InvestorInstitutional]);
    $institution->membership->update(['status' => MembershipStatus::UnderReview, 'submitted_at' => now()]);
    $institution->investorProfile->sectors()->sync([$education->id]);

    $this->approvedInvestor(); // no preferences
    $this->actingAs($this->admin, 'api');

    $total = fn (string $q): int => $this->getJson('/api/v1/admin/memberships?'.$q)->assertOk()->json('meta.total');

    expect($total('filter[sector]=education'))->toBe(2)
        ->and($total("sector={$education->id}&region=riyadh"))->toBe(1)
        ->and($total('filter[stage]=seed&filter[type]=equity&filter[range]=r2'))->toBe(1)
        ->and($total('filter[investor_type]=institutional'))->toBe(1)
        ->and($total('filter[status]=approved,under_review&filter[sector]=education'))->toBe(2)
        ->and($total('filter[status]=approved&filter[investor_type]=institutional'))->toBe(0)
        ->and($total('filter[sector]=no_such_sector'))->toBe(0);

    $row = $this->getJson('/api/v1/admin/memberships?filter[investor_type]=institutional')->json('data.0');
    expect($row['user']['uuid'])->toBe($institution->uuid);
});

it('filters the opportunities queue by assignee, including unassigned files', function (): void {
    $reviewer = $this->staff('reviewer');
    Opportunity::factory()->status(OpportunityStatus::New)->create(['assigned_reviewer_id' => $reviewer->id]);
    Opportunity::factory()->status(OpportunityStatus::New)->create();
    $this->actingAs($this->admin, 'api');

    expect($this->getJson("/api/v1/admin/opportunities?filter[assignee]={$reviewer->id}")->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/admin/opportunities?filter[assignee]=none')->assertOk()->json('meta.total'))->toBe(1);
});

it('searches matching candidates by name / email and filters them, keeping COI exclusion and nomination rules', function (): void {
    $education = oversightTerm('sectors', 'education');
    $opportunity = Opportunity::factory()->eligible()->create(['name' => 'منصة نور التعليمية']);

    $wanted = $this->approvedInvestor();
    $wanted->update(['name' => 'سلمان الباحث', 'email' => 'salman.search@investor.test']);
    $wanted->investorProfile->sectors()->sync([$education->id]);

    $conflicted = $this->approvedInvestor();
    $conflicted->update(['name' => 'سلمان المتعارض']);
    $conflicted->investorProfile->update(['conflicts_disclosure' => 'شريك في منصة نور التعليمية']);

    $registered = $this->registeredInvestor();
    $registered->update(['name' => 'سلمان غير المعتمد']);
    $this->approvedInvestor();

    $this->actingAs($this->admin, 'api');
    $ids = fn (string $q): array => collect($this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}/candidates?{$q}")->assertOk()->json('data.candidates'))->pluck('investor.id')->all();

    expect($ids('search=سلمان'))->toBe([$wanted->id])               // COI + non-approved still excluded
        ->and($ids('filter[search]=salman.search'))->toBe([$wanted->id])
        ->and($ids('filter[sector]=education'))->toBe([$wanted->id])
        ->and($ids('filter[investor_type]=institutional'))->toBe([])
        ->and($ids('limit=50'))->toHaveCount(2);

    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/nominations", ['investor_user_ids' => [$wanted->id], 'rationale' => 'ترشيح يدوي بعد البحث عن المستثمر'])->assertCreated();
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/nominations", ['investor_user_ids' => [$conflicted->id], 'rationale' => 'ترشيح يدوي بعد البحث عن المستثمر'])
        ->assertStatus(422)->assertJsonPath('code', 'coi_excluded');
});

// ───────────────────────────────────────────────────────────── ADM-06

it('merges documents, notes, completion requests, meetings and nominations into the staff timeline', function (): void {
    $owner = $this->entrepreneur();
    $opportunity = Opportunity::factory()->create(['owner_user_id' => $owner->id]);
    $sm = app(OpportunityStateMachine::class);
    $sm->transition($opportunity, OpportunityStatus::New, $owner, 'submit');
    $document = Document::factory()->forOpportunity($opportunity)->create(['title' => 'العرض التقديمي']);

    $this->actingAs($this->admin, 'api');
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/documents/{$document->uuid}/review", ['status' => 'accepted'])->assertOk();
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/notes", ['body' => 'ملاحظة داخلية للفريق'])->assertCreated();
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/completion-requests", ['items' => [['field' => 'solution', 'note' => 'وضّح الحل']]])->assertCreated();
    Meeting::query()->create(['opportunity_id' => $opportunity->id, 'title' => 'اجتماع تعارف', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'organizer_user_id' => $this->admin->id, 'status' => MeetingStatus::Scheduled]);
    $investor = $this->approvedInvestor();
    Nomination::query()->create(['opportunity_id' => $opportunity->id, 'investor_user_id' => $investor->id, 'nominated_by' => $this->admin->id, 'match_score' => 2, 'rationale' => 'سبب الترشيح', 'sent_at' => now()]);

    $events = $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}")->assertOk()->json('data.timeline_events');
    $types = array_column($events, 'type');

    expect($types)->toContain('status', 'document_uploaded', 'document_reviewed', 'note', 'completion_requested', 'meeting_scheduled', 'nomination_sent')
        ->and($types[0])->toBe('status');
    $timestamps = array_map(fn (array $e): int => strtotime((string) $e['at']), $events);
    $sorted = $timestamps;
    sort($sorted);
    expect($timestamps)->toBe($sorted);

    $reviewed = collect($events)->firstWhere('type', 'document_reviewed');
    expect($reviewed['data']['status'])->toBe('accepted')->and($reviewed['actor']['id'])->toBe($this->admin->id)
        ->and(collect($events)->firstWhere('type', 'nomination_sent')['data']['investor']['id'])->toBe($investor->id);

    // Owner view: status timeline only, never the merged staff events.
    $this->actingAs($owner, 'api');
    expect($this->getJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}")->assertOk()->json('data'))->not->toHaveKey('timeline_events');
});

it('serves the admin user file with membership history and activity, permission-gated', function (): void {
    $investor = $this->registeredInvestor();
    $investor->investorProfile()->firstOrCreate(['user_id' => $investor->id])->update(['completion_percent' => 90]);
    $officer = $this->staff('membership_officer');
    $membership = $investor->membership;
    $membership->update(['status' => MembershipStatus::UnderReview, 'submitted_at' => now()]);
    app(MembershipService::class)->transition($membership->fresh(), MembershipStatus::Approved, $officer, 'مكتمل');
    $opportunity = Opportunity::factory()->eligible()->create();
    Interest::query()->create(['opportunity_id' => $opportunity->id, 'investor_user_id' => $investor->id, 'status' => 'sent']);

    $this->actingAs($this->admin, 'api');
    $file = $this->getJson("/api/v1/admin/users/{$investor->id}")->assertOk()->json('data');

    expect($file)->toHaveKeys(['id', 'email', 'phone', 'type', 'status', 'two_factor_enabled', 'email_verified_at', 'membership', 'membership_history', 'interests', 'nominations', 'opportunities', 'activity', 'can'])
        ->and($file['membership']['status'])->toBe('approved')
        ->and($file['membership_history'][0]['to'])->toBe('approved')
        ->and($file['membership_history'][0]['actor']['id'])->toBe($officer->id)
        ->and($file['interests'][0]['opportunity']['uuid'])->toBe($opportunity->uuid)
        ->and(collect($file['activity'])->pluck('action'))->toContain('membership.transition');

    // Membership officer: investors only, no audit trail.
    $this->actingAs($officer, 'api');
    $asOfficer = $this->getJson("/api/v1/admin/users/{$investor->id}")->assertOk()->json('data');
    expect($asOfficer['activity'])->toBe([])->and($asOfficer['can']['view_activity'])->toBeFalse()->and($asOfficer['email'])->toBe($investor->email);
    $this->getJson("/api/v1/admin/users/{$this->admin->id}")->assertForbidden();

    $this->actingAs($this->staff('reviewer'), 'api');
    $this->getJson("/api/v1/admin/users/{$investor->id}")->assertForbidden();
});

it('lists an owner\'s opportunities on the user file', function (): void {
    $owner = $this->entrepreneur();
    $opportunity = Opportunity::factory()->status(OpportunityStatus::New)->create(['owner_user_id' => $owner->id]);
    $this->actingAs($this->admin, 'api');

    $file = $this->getJson("/api/v1/admin/users/{$owner->id}")->assertOk()->json('data');
    expect($file['opportunities'][0]['uuid'])->toBe($opportunity->uuid)->and($file['membership'])->toBeNull();
});

// ───────────────────────────────────────────────────────────── AUD-01

it('logs staff views of opportunity, membership and user files, throttled per user per record', function (): void {
    $opportunity = Opportunity::factory()->status(OpportunityStatus::New)->create();
    $investor = $this->approvedInvestor();
    $this->actingAs($this->admin, 'api');

    $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}")->assertOk();
    $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}")->assertOk();
    $this->getJson("/api/v1/admin/memberships/{$investor->membership->id}")->assertOk();
    $this->getJson("/api/v1/admin/memberships/{$investor->membership->id}")->assertOk();
    $this->getJson("/api/v1/admin/users/{$investor->id}")->assertOk();
    $this->getJson("/api/v1/admin/users/{$investor->id}")->assertOk();

    $count = fn (string $action): int => AuditLog::query()->where('action', $action)->where('user_id', $this->admin->id)->count();
    expect($count('opportunity.viewed'))->toBe(1)->and($count('membership.viewed'))->toBe(1)->and($count('user.viewed'))->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'opportunity.viewed', 'auditable_type' => 'opportunity', 'auditable_id' => $opportunity->id]);

    // Another viewer is logged separately; the same viewer again after the 10-minute window.
    $this->actingAs($this->staff('reviewer'), 'api');
    $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}")->assertOk();
    expect(AuditLog::query()->where('action', 'opportunity.viewed')->count())->toBe(2);

    $this->travel(11)->minutes();
    $this->actingAs($this->admin, 'api');
    $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}")->assertOk();
    expect($count('opportunity.viewed'))->toBe(2);
});

// ───────────────────────────────────────────────────────────── AUD-02

it('refuses raw UPDATE and DELETE on audit_logs at the database level', function (): void {
    $this->actingAs($this->admin, 'api');
    $this->getJson('/api/v1/admin/reports/opportunities_funnel')->assertOk(); // writes report.viewed
    expect(AuditLog::query()->count())->toBeGreaterThan(0);

    expect(fn () => DB::table('audit_logs')->update(['action' => 'tampered']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('audit_logs')->delete())->toThrow(QueryException::class)
        ->and(AuditLog::query()->where('action', 'tampered')->exists())->toBeFalse();
});

it('still lets a user row be removed while their audit rows stay', function (): void {
    $user = $this->entrepreneur();
    app(AuditLogger::class)->log('test.entry', $user, null, null, $user);

    $user->forceDelete();

    expect(AuditLog::query()->where('action', 'test.entry')->count())->toBe(1);
});

// ───────────────────────────────────────────────────────────── AUD-03

it('filters audit logs by a date range (whole days) and returns the ip', function (): void {
    $this->actingAs($this->admin, 'api');
    $this->travelTo(now()->subDays(5));
    app(AuditLogger::class)->log('test.old', null, null, null, $this->admin);
    $this->travelBack();
    app(AuditLogger::class)->log('test.today', null, null, null, $this->admin);

    $today = now()->format('Y-m-d');
    $rows = $this->getJson("/api/v1/admin/audit-logs?filter[action]=test.&filter[from]={$today}&filter[to]={$today}")->assertOk()->json('data');
    expect(array_column($rows, 'action'))->toBe(['test.today'])->and($rows[0])->toHaveKey('ip');

    $old = now()->subDays(5)->format('Y-m-d');
    expect($this->getJson("/api/v1/admin/audit-logs?filter[action]=test.&to={$old}")->json('meta.total'))->toBe(1);

    $this->get("/api/v1/admin/audit-logs/export.xlsx?filter[action]=test.&filter[from]={$today}")->assertOk();
    expect(AuditLog::query()->where('action', 'audit_log.export')->latest('id')->first()->after['rows'])->toBe(1);
});

// ───────────────────────────────────────────────────────────── ACC-04

it('logs denied access for authenticated users once per request and keeps the error format', function (): void {
    $reviewer = $this->staff('reviewer');
    $this->actingAs($reviewer, 'api');

    $this->getJson('/api/v1/admin/audit-logs')->assertForbidden()
        ->assertJsonStructure(['message', 'errors', 'code', 'request_id'])
        ->assertJsonPath('code', 'forbidden');
    $this->getJson('/api/v1/admin/memberships')->assertForbidden(); // policy (AuthorizationException)

    $rows = AuditLog::query()->where('action', 'access.denied')->where('user_id', $reviewer->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->after)->toMatchArray(['method' => 'GET', 'path' => '/api/v1/admin/audit-logs', 'route' => 'v1.admin.audit-logs', 'status' => 403])
        ->and($rows[1]->after['route'])->toBe('v1.admin.memberships.index');

    // An investor in the staff area (EnsureStaff) and a DomainException 403 are logged too.
    $investor = $this->approvedInvestor();
    $this->actingAs($investor, 'api');
    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    expect(AuditLog::query()->where('action', 'access.denied')->where('user_id', $investor->id)->count())->toBe(1);
});

it('never logs denied access for guests', function (): void {
    $document = Document::factory()->create();
    $this->getJson("/api/v1/documents/{$document->uuid}/stream")->assertForbidden(); // missing signature

    expect(AuditLog::query()->where('action', 'access.denied')->count())->toBe(0);
});

// ───────────────────────────────────────────────────────────── ADM-07

it('requires the supervisor sign-off (evaluations.approve) for not_eligible', function (): void {
    $reviewer = $this->staff('reviewer');
    $opportunity = Opportunity::factory()->status(OpportunityStatus::UnderReview)->create();

    $this->actingAs($reviewer, 'api');
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/transition", ['to' => 'not_eligible', 'reason' => 'خارج نطاق المجلس'])
        ->assertForbidden()->assertJsonPath('message', __('messages.transitions.supervisor_required'));
    $offered = $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}")->assertOk()->json('data.allowed_transitions');
    expect($offered)->toContain('needs_completion')->not->toContain('not_eligible');

    // The reviewer flow is unchanged.
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/transition", ['to' => 'needs_completion'])->assertOk();

    $other = Opportunity::factory()->status(OpportunityStatus::UnderReview)->create();
    $this->actingAs($this->admin, 'api');
    expect($this->getJson("/api/v1/admin/opportunities/{$other->uuid}")->json('data.allowed_transitions'))->toContain('not_eligible');
    $this->postJson("/api/v1/admin/opportunities/{$other->uuid}/transition", ['to' => 'not_eligible', 'reason' => 'خارج نطاق المجلس'])
        ->assertOk()->assertJsonPath('data.status', 'not_eligible');

    // A custom role holding both permissions can sign too (permissions, not role names).
    Role::findOrCreate('screening_lead', 'api')->syncPermissions(['opportunities.view', 'opportunities.transition', 'evaluations.approve']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $lead = $this->staff('screening_lead');
    $third = Opportunity::factory()->status(OpportunityStatus::New)->create();
    expect(app(OpportunityStateMachine::class)->transition($third, OpportunityStatus::NotEligible, $lead, 'سبب')->status)->toBe(OpportunityStatus::NotEligible);
    expect(fn () => app(OpportunityStateMachine::class)->transition(Opportunity::factory()->status(OpportunityStatus::New)->create(), OpportunityStatus::NotEligible, $reviewer, 'سبب'))
        ->toThrow(AuthorizationException::class);
});

it('derives the approval matrix from the live role permissions', function (): void {
    $this->actingAs($this->admin, 'api');

    $rows = collect($this->getJson('/api/v1/admin/roles/approval-matrix')->assertOk()->json('data.decisions'))->keyBy('key');
    $roles = fn (string $key) => collect($rows[$key]['roles'])->pluck('name')->all();

    expect($roles('membership_approve'))->toContain('super_admin', 'membership_officer')->not->toContain('reviewer')
        ->and($roles('opportunity_not_eligible'))->toBe(['super_admin'])
        ->and($rows['opportunity_not_eligible']['permissions'])->toBe(['opportunities.transition', 'evaluations.approve'])
        ->and($roles('opportunity_assign_reviewer'))->toContain('reviewer')
        ->and($rows['opportunity_assign_reviewer']['mode'])->toBe('any')
        ->and($roles('teaser_approve'))->toContain('matching_officer')
        ->and($roles('report_export'))->toContain('reports_viewer');

    // Editing a role in the roles screen updates the matrix.
    $reviewer = Role::findByName('reviewer', 'api');
    $this->patchJson("/api/v1/admin/roles/{$reviewer->id}", ['permissions' => ['opportunities.view', 'opportunities.transition', 'evaluations.assign', 'evaluations.approve', 'reports.export']])->assertOk();
    $rows = collect($this->getJson('/api/v1/admin/roles/approval-matrix')->json('data.decisions'))->keyBy('key');
    expect(collect($rows['opportunity_not_eligible']['roles'])->pluck('name')->all())->toContain('reviewer')
        // reports.export alone is not enough: the role must also be in reports.allowed_export_roles.
        ->and(collect($rows['report_export']['excluded_roles'])->pluck('name')->all())->toContain('reviewer');

    $this->actingAs($this->staff('reviewer'), 'api');
    $this->getJson('/api/v1/admin/roles/approval-matrix')->assertForbidden();
});
