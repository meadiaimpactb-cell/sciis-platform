<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Models\AdvisoryReferral;
use App\Models\AuditLog;
use App\Models\CompletionRequest;
use App\Models\EvaluationAssignment;
use App\Models\Interest;
use App\Models\Opportunity;
use App\Models\PeriodicReport;
use App\Models\SavedReport;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Notifications\AdvisoryReferralNotification;
use App\Notifications\CompletionReminderNotification;
use App\Notifications\PendingRequestReminderNotification;
use App\Notifications\PeriodicReportNotification;
use App\Notifications\ScheduledReportNotification;
use App\Services\OpportunityStateMachine;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Notification;

/**
 * RPT-03 drill-down, RPT-04 report builder, RPT-05 schedules, RPT-06 PDF, journey 11.10 periodic approval,
 * ADM-08 bulk actions, ADM-09 auto-assignment / evaluator suggestions, EVL-04 advisory referrals.
 */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->admin = $this->staff('super_admin');
});

function rbSubmitted(array $attrs = []): Opportunity
{
    return Opportunity::factory()->status(OpportunityStatus::New)->create($attrs + ['reference_no' => 'A-2026-'.random_int(1000, 9999)]);
}

function rbTerm(string $group, string $key): TaxonomyTerm
{
    return TaxonomyTerm::query()->where('group', $group)->where('key', $key)->firstOrFail();
}

// ───────────────────────────────────────────────────────────── RPT-03

it('drills a KPI bar down to its records, hiding confidential fields without the domain permission', function (): void {
    $investor = $this->approvedInvestor();
    $this->registeredInvestor();

    $this->actingAs($this->admin, 'api');
    $report = $this->getJson('/api/v1/admin/reports/memberships_by_status')->assertOk()->json('data');
    expect($report['drill'])->toBe('memberships');

    $drill = $this->getJson('/api/v1/admin/reports/memberships_by_status/drilldown?segment=approved')->assertOk()->json('data');
    expect($drill['total'])->toBe(1)
        ->and($drill['rows'][0]['investor'])->toBe($investor->name)
        ->and($drill['rows'][0]['_link'])->toBe(['type' => 'membership', 'id' => $investor->membership->id]);

    // reports_viewer: reports.view but no memberships.review → no investor names, no record links.
    $this->actingAs($this->staff('reports_viewer'), 'api');
    $limited = $this->getJson('/api/v1/admin/reports/memberships_by_status/drilldown?segment=approved')->assertOk()->json('data');
    expect(array_column($limited['columns'], 'key'))->not->toContain('investor')
        ->and($limited['rows'][0])->not->toHaveKey('investor')
        ->and($limited['rows'][0])->not->toHaveKey('_link');

    expect(AuditLog::query()->where('action', 'report.drilldown')->count())->toBe(2);
});

it('drills the funnel (cumulative stages) and the sector chart by sector key', function (): void {
    $education = rbTerm('sectors', 'education');
    rbSubmitted(['sector_id' => $education->id]);
    Opportunity::factory()->eligible()->create(['sector_id' => $education->id]);
    rbSubmitted();
    $this->actingAs($this->admin, 'api');

    $sector = $this->getJson('/api/v1/admin/reports/opportunities_by_sector_stage_region')->assertOk()->json('data');
    $bar = collect($sector['series'])->firstWhere('key', 'education');
    expect($bar['value'])->toEqual(2.0);

    expect($this->getJson('/api/v1/admin/reports/opportunities_by_sector_stage_region/drilldown?segment=education')->assertOk()->json('data.total'))->toBe(2)
        ->and($this->getJson('/api/v1/admin/reports/opportunities_funnel/drilldown?segment=eligible')->assertOk()->json('data.total'))->toBe(1)
        ->and($this->getJson('/api/v1/admin/reports/opportunities_funnel/drilldown?segment=submitted')->assertOk()->json('data.total'))->toBe(3);

    $this->getJson('/api/v1/admin/reports/eligibility_rate/drilldown?segment=x')->assertStatus(422);
    $this->getJson('/api/v1/admin/reports/nope/drilldown?segment=x')->assertNotFound();
});

// ───────────────────────────────────────────────────────────── RPT-04

it('builds a custom report: catalog, preview, grouping and column sensitivity', function (): void {
    rbSubmitted(['funding_amount' => 750000]);
    rbSubmitted();
    $this->actingAs($this->admin, 'api');

    $catalog = $this->getJson('/api/v1/admin/report-builder')->assertOk()->json('data');
    expect(array_column($catalog['datasets'], 'key'))->toBe(['opportunities', 'memberships', 'interests', 'nominations', 'meetings', 'evaluations', 'accounts']);

    $preview = $this->postJson('/api/v1/admin/report-builder/preview', [
        'dataset' => 'opportunities', 'columns' => ['reference_no', 'status', 'funding_amount'], 'filters' => ['status' => ['new']],
    ])->assertOk()->json('data');
    expect($preview['total'])->toBe(2)->and(array_column($preview['columns'], 'key'))->toBe(['reference_no', 'status', 'funding_amount']);

    $grouped = $this->postJson('/api/v1/admin/report-builder/preview', ['dataset' => 'opportunities', 'group_by' => 'status'])->assertOk()->json('data');
    expect($grouped['grouped'])->toBeTrue()->and($grouped['rows'][0]['count'])->toBe(2)->and($grouped['series'])->toHaveCount(1);

    $this->postJson('/api/v1/admin/report-builder/preview', ['dataset' => 'opportunities', 'columns' => ['email']])->assertStatus(422);
    $this->postJson('/api/v1/admin/report-builder/preview', ['dataset' => 'payments'])->assertStatus(422);

    // membership_officer: reports.view without opportunities.view → the confidential funding amount is dropped.
    $this->actingAs($this->staff('membership_officer'), 'api');
    $limited = $this->postJson('/api/v1/admin/report-builder/preview', ['dataset' => 'opportunities', 'columns' => ['reference_no', 'funding_amount']])->assertOk()->json('data');
    expect(array_column($limited['columns'], 'key'))->toBe(['reference_no'])
        ->and($limited['rows'][0])->not->toHaveKey('_link');
});

it('saves named reports; only the author edits them; exports follow the export roles and are audited', function (): void {
    rbSubmitted();
    $viewer = $this->staff('reports_viewer');
    $this->actingAs($viewer, 'api');

    $uuid = $this->postJson('/api/v1/admin/saved-reports', [
        'name' => 'مشاريع جديدة', 'dataset' => 'opportunities', 'definition' => ['columns' => ['reference_no', 'status'], 'filters' => ['status' => ['new']]],
    ])->assertCreated()->json('data.uuid');

    expect($this->getJson("/api/v1/admin/saved-reports/{$uuid}/run")->assertOk()->json('data.total'))->toBe(1);

    $xlsx = $this->get("/api/v1/admin/saved-reports/{$uuid}/export?format=xlsx")->assertOk();
    expect($xlsx->headers->get('Content-Type'))->toContain('spreadsheetml');
    $pdf = $this->get('/api/v1/admin/report-builder/export?format=pdf&dataset=opportunities&columns[]=reference_no&columns[]=status')->assertOk();
    expect(substr((string) $pdf->getContent(), 0, 5))->toBe('%PDF-');
    expect(AuditLog::query()->where('action', 'report.export')->count())->toBe(2);

    // Another reports.view holder can read and run it, not change it; no export role → 403 on downloads.
    $officer = $this->staff('membership_officer');
    $this->actingAs($officer, 'api');
    expect($this->getJson('/api/v1/admin/saved-reports')->assertOk()->json('data.items.0.can_edit'))->toBeFalse();
    $this->patchJson("/api/v1/admin/saved-reports/{$uuid}", ['name' => 'تغيير'])->assertForbidden();
    $this->get("/api/v1/admin/saved-reports/{$uuid}/export?format=xlsx")->assertForbidden();

    $this->actingAs($viewer, 'api');
    $this->deleteJson("/api/v1/admin/saved-reports/{$uuid}")->assertNoContent();
    expect(SavedReport::query()->count())->toBe(0);
});

// ───────────────────────────────────────────────────────────── RPT-05

it('schedules a saved report and e-mails it with the attachment when due', function (): void {
    Notification::fake();
    rbSubmitted();
    $viewer = $this->staff('reports_viewer');
    $recipient = $this->staff('reviewer');
    $this->actingAs($viewer, 'api');

    $uuid = $this->postJson('/api/v1/admin/saved-reports', ['name' => 'أسبوعي', 'dataset' => 'opportunities', 'definition' => ['columns' => ['reference_no', 'status']]])->assertCreated()->json('data.uuid');

    // An investor cannot receive internal reports.
    $this->putJson("/api/v1/admin/saved-reports/{$uuid}/schedule", ['frequency' => 'weekly', 'format' => 'xlsx', 'recipients' => [$this->approvedInvestor()->id]])->assertStatus(422);

    $schedule = $this->putJson("/api/v1/admin/saved-reports/{$uuid}/schedule", ['frequency' => 'weekly', 'format' => 'pdf', 'recipients' => [$recipient->id]])->assertOk()->json('data.schedule');
    expect($schedule['frequency'])->toBe('weekly')->and($schedule['next_run_at'])->not->toBeNull();

    // Not due yet → nothing sent.
    $this->artisan('sciis:scheduled-reports')->assertSuccessful();
    Notification::assertNothingSent();

    $saved = SavedReport::query()->where('uuid', $uuid)->firstOrFail();
    $saved->update(['next_run_at' => now()->subMinute()]);
    $this->artisan('sciis:scheduled-reports')->assertSuccessful();

    Notification::assertSentTo($recipient, ScheduledReportNotification::class, function (ScheduledReportNotification $n) use ($recipient): bool {
        $mail = $n->toMail($recipient);

        return count($mail->rawAttachments) === 1 && str_starts_with($mail->rawAttachments[0]['data'], '%PDF-') && str_ends_with($mail->rawAttachments[0]['name'], '.pdf');
    });
    expect($saved->refresh()->next_run_at->isFuture())->toBeTrue()
        ->and($saved->last_sent_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'report.scheduled_sent')->count())->toBe(1);

    $this->putJson("/api/v1/admin/saved-reports/{$uuid}/schedule", ['frequency' => null])->assertOk()->assertJsonPath('data.schedule.frequency', null);
});

// ───────────────────────────────────────────────────────────── RPT-06

it('exports a KPI report as a branded PDF with the same roles and audit as the xlsx export', function (): void {
    rbSubmitted();
    $this->actingAs($this->staff('reports_viewer'), 'api');

    $response = $this->get('/api/v1/admin/reports/opportunities_funnel/export.pdf')->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
        ->and(AuditLog::query()->where('action', 'report.export')->latest('id')->first()->after['format'])->toBe('pdf');

    $this->actingAs($this->staff('membership_officer'), 'api');
    $this->get('/api/v1/admin/reports/opportunities_funnel/export.pdf')->assertForbidden();
});

// ───────────────────────────────────────────────────────────── Journey 11.10

it('runs the periodic report flow: draft → approve (reports.approve) → publish to an audience', function (): void {
    Notification::fake();
    rbSubmitted();
    $author = $this->staff('reports_viewer');
    $this->actingAs($author, 'api');

    $uuid = $this->postJson('/api/v1/admin/periodic-reports', ['period_type' => 'quarterly'])->assertCreated()->json('data.uuid');
    $draft = PeriodicReport::query()->where('uuid', $uuid)->firstOrFail();
    expect($draft->status)->toBe('draft')->and($draft->snapshot['kpis'])->toHaveCount(12);
    Notification::assertSentTo($this->admin, PeriodicReportNotification::class);

    // reports.view cannot approve; staff without reports.view do not see drafts.
    $this->postJson("/api/v1/admin/periodic-reports/{$uuid}/approve")->assertForbidden();
    $evaluator = $this->staff('evaluator');
    $this->actingAs($evaluator, 'api');
    expect($this->getJson('/api/v1/admin/periodic-reports')->assertOk()->json('data.items'))->toBe([]);
    $this->getJson("/api/v1/admin/periodic-reports/{$uuid}")->assertNotFound();

    $this->actingAs($this->admin, 'api');
    $this->postJson("/api/v1/admin/periodic-reports/{$uuid}/publish")->assertStatus(409); // must be approved first
    $this->postJson("/api/v1/admin/periodic-reports/{$uuid}/approve", ['note' => 'الأرقام مطابقة'])->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/admin/periodic-reports/{$uuid}/return")->assertStatus(422); // note required
    $this->postJson("/api/v1/admin/periodic-reports/{$uuid}/publish", ['audience' => 'staff'])->assertOk()->assertJsonPath('data.status', 'published');

    // Published to staff: every staff account reads it; the public API does not list it.
    $this->actingAs($evaluator, 'api');
    expect($this->getJson('/api/v1/admin/periodic-reports')->assertOk()->json('data.items.0.uuid'))->toBe($uuid);
    expect(substr((string) $this->get("/api/v1/admin/periodic-reports/{$uuid}/export.pdf")->assertOk()->getContent(), 0, 5))->toBe('%PDF-');
    $this->getJson('/api/v1/public/periodic-reports')->assertOk()->assertJsonCount(0, 'data');

    // Re-publish to the public: aggregates only (no overdue record list).
    $this->actingAs($this->admin, 'api');
    $this->postJson("/api/v1/admin/periodic-reports/{$uuid}/unpublish")->assertOk();
    $this->postJson("/api/v1/admin/periodic-reports/{$uuid}/publish", ['audience' => 'public'])->assertOk();
    $public = $this->getJson("/api/v1/public/periodic-reports/{$uuid}")->assertOk()->json('data');
    expect(array_column($public['kpis'], 'key'))->not->toContain('overdue_items')
        ->and($public['kpis'][0])->not->toHaveKey('rows');

    // A published period is locked against regeneration.
    $this->postJson('/api/v1/admin/periodic-reports', ['period_type' => 'quarterly'])->assertStatus(409);
    expect(AuditLog::query()->where('auditable_type', 'periodic_report')->pluck('action')->unique()->values()->all())
        ->toContain('periodic_report.generated', 'periodic_report.approved', 'periodic_report.published', 'periodic_report.unpublished');
});

// ───────────────────────────────────────────────────────────── ADM-08

it('bulk-assigns, reminds and exports opportunities with one audit row per record', function (): void {
    Notification::fake();
    $reviewer = $this->staff('reviewer');
    $withCompletion = rbSubmitted();
    CompletionRequest::query()->create(['opportunity_id' => $withCompletion->id, 'requested_by' => $this->admin->id, 'due_at' => now()->addDays(3), 'items' => ['السجل التجاري']]);
    $withAccess = Opportunity::factory()->eligible()->create();
    Interest::factory()->create(['opportunity_id' => $withAccess->id]);
    $nothing = rbSubmitted();
    $uuids = [$withCompletion->uuid, $withAccess->uuid, $nothing->uuid];
    $this->actingAs($this->admin, 'api');

    $assign = $this->postJson('/api/v1/admin/bulk/opportunities/assign', ['uuids' => $uuids, 'reviewer_user_id' => $reviewer->id])->assertOk()->json('data');
    expect($assign['updated'])->toBe(3)
        ->and(Opportunity::query()->where('assigned_reviewer_id', $reviewer->id)->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'opportunity.assigned')->count())->toBe(3);
    $this->postJson('/api/v1/admin/bulk/opportunities/assign', ['uuids' => $uuids, 'reviewer_user_id' => $this->approvedInvestor()->id])->assertStatus(422);

    $remind = $this->postJson('/api/v1/admin/bulk/opportunities/remind', ['uuids' => $uuids])->assertOk()->json('data');
    expect($remind['updated'])->toBe(2)->and($remind['skipped'][0]['reason'])->toBe('no_pending_request');
    Notification::assertSentTo($withCompletion->owner, CompletionReminderNotification::class);
    Notification::assertSentTo($withAccess->owner, PendingRequestReminderNotification::class);
    expect($this->postJson('/api/v1/admin/bulk/opportunities/remind', ['uuids' => [$withCompletion->uuid]])->json('data.skipped.0.reason'))->toBe('recently_reminded');

    $export = $this->get('/api/v1/admin/bulk/opportunities/export?format=xlsx&uuids='.implode(',', $uuids))->assertOk();
    expect($export->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and(AuditLog::query()->where('action', 'opportunity.exported')->count())->toBe(3);

    // Without the queue permission the bulk endpoints are closed.
    $this->actingAs($this->staff('membership_officer'), 'api');
    $this->postJson('/api/v1/admin/bulk/opportunities/remind', ['uuids' => $uuids])->assertForbidden();
});

it('bulk-assigns and reminds memberships', function (): void {
    Notification::fake();
    $officer = $this->staff('membership_officer');
    $pending = $this->registeredInvestor();
    $completion = $this->registeredInvestor();
    $completion->membership->update(['status' => MembershipStatus::NeedsCompletion, 'completion_note' => 'أرفق السيرة الذاتية']);
    $approved = $this->approvedInvestor();
    $ids = [$pending->membership->id, $completion->membership->id, $approved->membership->id];
    $this->actingAs($officer, 'api');

    expect($this->postJson('/api/v1/admin/bulk/memberships/assign', ['ids' => $ids, 'reviewer_user_id' => $officer->id])->assertOk()->json('data.updated'))->toBe(3);
    expect($this->getJson('/api/v1/admin/memberships?filter[status]=approved')->assertOk()->json('data.0.assigned_reviewer.id'))->toBe($officer->id);
    $this->postJson('/api/v1/admin/bulk/memberships/assign', ['ids' => $ids, 'reviewer_user_id' => $this->staff('evaluator')->id])->assertStatus(422);

    $remind = $this->postJson('/api/v1/admin/bulk/memberships/remind', ['ids' => $ids])->assertOk()->json('data');
    expect($remind['updated'])->toBe(2)->and($remind['skipped'][0]['id'])->toBe((string) $approved->membership->id);
    Notification::assertSentTo($pending, PendingRequestReminderNotification::class);
    Notification::assertSentTo($completion, PendingRequestReminderNotification::class);
    Notification::assertNotSentTo($approved, PendingRequestReminderNotification::class);
    expect(AuditLog::query()->where('action', 'membership.reminder_sent')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'membership.assigned')->count())->toBe(3);
});

// ───────────────────────────────────────────────────────────── ADM-09

it('auto-assigns new submissions to the least-loaded reviewer when enabled', function (): void {
    $busy = $this->staff('reviewer');
    $free = $this->staff('reviewer');
    rbSubmitted(['assigned_reviewer_id' => $busy->id]);
    $machine = app(OpportunityStateMachine::class);

    // Off by default.
    $o = $machine->systemTransition(Opportunity::factory()->create(), OpportunityStatus::New, 'submit');
    expect($o->refresh()->assigned_reviewer_id)->toBeNull();

    app(SettingsService::class)->set('assignment.auto_assign_reviewer', true);
    $o = $machine->systemTransition(Opportunity::factory()->create(), OpportunityStatus::New, 'submit');
    expect($o->refresh()->assigned_reviewer_id)->toBe($free->id)
        ->and($o->review_due_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'opportunity.auto_assigned')->count())->toBe(1);

    // Round robin: after $free comes the next reviewer id (wrapping).
    app(SettingsService::class)->set('assignment.reviewer_strategy', 'round_robin');
    $next = $machine->systemTransition(Opportunity::factory()->create(), OpportunityStatus::New, 'submit');
    expect($next->refresh()->assigned_reviewer_id)->toBe($busy->id);

    // super_admin-only accounts are never in the pool.
    $this->actingAs($this->admin, 'api');
    expect(collect($this->getJson('/api/v1/admin/assignment/reviewers')->assertOk()->json('data.items'))->pluck('id')->all())->not->toContain($this->admin->id);

    // Bulk "auto" spreads files by workload.
    $a = rbSubmitted();
    $b = rbSubmitted();
    app(SettingsService::class)->set('assignment.reviewer_strategy', 'least_workload');
    expect($this->postJson('/api/v1/admin/bulk/opportunities/assign', ['uuids' => [$a->uuid, $b->uuid], 'auto' => true])->assertOk()->json('data.updated'))->toBe(2);
});

it('suggests two evaluators by least workload, excluding declared conflicts', function (): void {
    $loaded = $this->staff('evaluator');
    $conflicted = $this->staff('evaluator');
    $free1 = $this->staff('evaluator');
    $free2 = $this->staff('evaluator');
    $other = rbSubmitted();
    EvaluationAssignment::query()->create(['opportunity_id' => $other->id, 'evaluator_user_id' => $loaded->id, 'assigned_by' => $this->admin->id, 'status' => AssignmentStatus::Assigned]);
    $file = rbSubmitted();
    EvaluationAssignment::query()->create(['opportunity_id' => $file->id, 'evaluator_user_id' => $conflicted->id, 'assigned_by' => $this->admin->id, 'status' => AssignmentStatus::Withdrawn, 'coi_declared_at' => now(), 'coi_has_conflict' => true]);
    $this->actingAs($this->staff('reviewer'), 'api');

    $ids = collect($this->getJson("/api/v1/admin/opportunities/{$file->uuid}/evaluation/suggest")->assertOk()->json('data.items'))->pluck('id')->all();
    expect($ids)->toBe([$free1->id, $free2->id]);

    $this->actingAs($this->staff('membership_officer'), 'api');
    $this->getJson("/api/v1/admin/opportunities/{$file->uuid}/evaluation/suggest")->assertForbidden();
});

// ───────────────────────────────────────────────────────────── EVL-04

it('refers a file to an advisory member who sees only it and answers until expiry', function (): void {
    Notification::fake();
    $advisor = $this->staff('advisory_member');
    $referred = rbSubmitted();
    $other = rbSubmitted();
    $supervisor = $this->staff('reviewer'); // evaluations.assign
    $this->actingAs($supervisor, 'api');

    $this->postJson("/api/v1/admin/opportunities/{$referred->uuid}/advisory-referrals", ['advisory_user_id' => $this->staff('evaluator')->id, 'question' => 'هل نموذج الإيرادات قابل للتوسع؟', 'expires_at' => now()->addDays(7)->toDateString()])->assertStatus(422);
    $id = $this->postJson("/api/v1/admin/opportunities/{$referred->uuid}/advisory-referrals", [
        'advisory_user_id' => $advisor->id, 'question' => 'هل نموذج الإيرادات قابل للتوسع؟', 'expires_at' => now()->addDays(7)->toDateString(),
    ])->assertCreated()->json('data.id');
    Notification::assertSentTo($advisor, AdvisoryReferralNotification::class);

    // The advisory member: queue and file limited to the referral.
    $this->actingAs($advisor, 'api');
    expect(collect($this->getJson('/api/v1/admin/opportunities')->assertOk()->json('data'))->pluck('uuid')->all())->toBe([$referred->uuid]);
    $this->getJson("/api/v1/admin/opportunities/{$referred->uuid}")->assertOk();
    $this->getJson("/api/v1/admin/opportunities/{$other->uuid}")->assertForbidden();
    expect($this->getJson('/api/v1/admin/advisory-referrals')->assertOk()->json('data.items'))->toHaveCount(1);
    $this->postJson("/api/v1/admin/opportunities/{$other->uuid}/advisory-referrals", ['advisory_user_id' => $advisor->id, 'question' => 'سؤال لا يحق له طرحه', 'expires_at' => now()->addDay()->toDateString()])->assertForbidden();

    $this->postJson("/api/v1/admin/advisory-referrals/{$id}/opinion", ['opinion' => 'النموذج قابل للتوسع بشرط شراكة توزيع.'])->assertOk();
    Notification::assertSentTo($supervisor, AdvisoryReferralNotification::class);

    // Staff read the opinion on the file.
    $this->actingAs($supervisor, 'api');
    expect($this->getJson("/api/v1/admin/opportunities/{$referred->uuid}/advisory-referrals")->assertOk()->json('data.items.0.opinion'))->toBe('النموذج قابل للتوسع بشرط شراكة توزيع.');

    // Access ends at expiry: no file, no new opinion.
    AdvisoryReferral::query()->whereKey($id)->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($advisor, 'api');
    $this->getJson("/api/v1/admin/opportunities/{$referred->uuid}")->assertForbidden();
    $this->postJson("/api/v1/admin/advisory-referrals/{$id}/opinion", ['opinion' => 'تحديث بعد انتهاء المدة المسموحة'])->assertStatus(409);
    expect($this->getJson('/api/v1/admin/opportunities')->assertOk()->json('data'))->toBe([]);

    // Another advisory member cannot answer someone else's referral.
    $this->actingAs($this->staff('advisory_member'), 'api');
    $this->postJson("/api/v1/admin/advisory-referrals/{$id}/opinion", ['opinion' => 'رأي من عضو آخر غير معني'])->assertForbidden();

    expect(AuditLog::query()->whereIn('action', ['advisory.referred', 'advisory.opinion_submitted'])->count())->toBe(2);
});
