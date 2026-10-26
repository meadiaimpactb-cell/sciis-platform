<?php

declare(strict_types=1);

use App\Enums\InterestStatus;
use App\Enums\OpportunityStatus;
use App\Models\Document;
use App\Models\FollowUp;
use App\Models\ImpactIndicator;
use App\Models\Interest;
use App\Models\Opportunity;
use App\Models\OpportunityStatusTransition;
use App\Models\User;
use App\Notifications\FollowUpNotification;
use App\Services\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * PRJ-01 milestone plan · PRJ-02 progress reports + reminders · PRJ-03 execution status (computed, override, escalation)
 * · IMP-01/02 indicator values + verification · ENT-10 owner updates · INV-06 investor portfolio.
 */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    Storage::fake('local');
    Notification::fake();

    $this->owner = $this->entrepreneur();
    $this->officer = $this->staff('impact_officer');
    $this->investor = $this->approvedInvestor();
    $this->opportunity = Opportunity::factory()->eligible()->create(['owner_user_id' => $this->owner->id, 'status' => OpportunityStatus::InExecution]);
    // Execution started 100 days ago (quarterly reports, 15 grace days → first report due in a few days).
    OpportunityStatusTransition::query()->create([
        'opportunity_id' => $this->opportunity->id, 'from_status' => 'negotiating', 'to_status' => 'in_execution', 'created_at' => now()->subDays(100),
    ]);
    Interest::factory()->create(['opportunity_id' => $this->opportunity->id, 'investor_user_id' => $this->investor->id, 'status' => InterestStatus::Connected]);
    $this->indicator = ImpactIndicator::query()->create(['opportunity_id' => $this->opportunity->id, 'name' => 'عدد المستفيدين', 'unit' => 'مستفيد', 'baseline' => 100, 'target' => 1100, 'is_primary' => true]);

    $this->ownerBase = "/api/v1/entrepreneur/follow-up/{$this->opportunity->uuid}";
    $this->adminBase = "/api/v1/admin/follow-up/{$this->opportunity->uuid}";
});

function followUpPdf(string $name = 'evidence.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n{$name}");
}

function followUpSent(User $user, string $key): bool
{
    return Notification::sent($user, FollowUpNotification::class)->contains(fn (FollowUpNotification $n): bool => $n->key() === $key);
}

/** Owner proposes one milestone (due in `$dueInDays`), submits, staff approve. */
function followUpApprovedPlan(object $t, int $dueInDays = 30): array
{
    $t->actingAs($t->owner);
    $milestone = $t->postJson("{$t->ownerBase}/milestones", ['title' => 'إطلاق الخدمة', 'deliverable' => 'نسخة تشغيلية', 'due_at' => now()->addDays($dueInDays)->toDateString(), 'budget_share' => 40])->assertCreated()->json('data');
    $t->postJson("{$t->ownerBase}/plan/submit")->assertOk();
    $t->actingAs($t->officer);
    $t->postJson("{$t->adminBase}/plan/review", ['decision' => 'approve'])->assertOk();

    return $milestone;
}

it('runs the milestone plan through proposal, change request and approval with an audit trail (PRJ-01)', function (): void {
    $this->actingAs($this->owner);
    $this->postJson("{$this->ownerBase}/plan/submit")->assertStatus(422)->assertJsonPath('code', 'plan_empty');
    $this->postJson("{$this->ownerBase}/milestones", ['title' => 'إطلاق الخدمة', 'deliverable' => 'نسخة تشغيلية', 'due_at' => now()->addMonth()->toDateString(), 'budget_share' => 60])->assertCreated();
    $this->postJson("{$this->ownerBase}/milestones", ['title' => 'التوسع', 'deliverable' => 'فرعان جديدان', 'due_at' => now()->addMonths(4)->toDateString(), 'budget_share' => 50])
        ->assertStatus(422)->assertJsonValidationErrors(['budget_share']);
    $this->postJson("{$this->ownerBase}/milestones", ['title' => 'ت', 'due_at' => 'tomorrow'])->assertStatus(422)->assertJsonValidationErrors(['title', 'deliverable', 'due_at']);

    $this->postJson("{$this->ownerBase}/plan/submit")->assertOk()->assertJsonPath('data.follow_up.plan_status', 'submitted');
    expect(followUpSent($this->officer, 'plan_submitted'))->toBeTrue();
    $this->postJson("{$this->ownerBase}/milestones", ['title' => 'مرحلة إضافية', 'deliverable' => 'مخرج', 'due_at' => now()->addMonth()->toDateString()])
        ->assertStatus(422)->assertJsonPath('code', 'plan_locked');

    $this->actingAs($this->officer);
    $this->postJson("{$this->adminBase}/plan/review", ['decision' => 'changes_requested'])->assertStatus(422)->assertJsonValidationErrors(['note']);
    $this->postJson("{$this->adminBase}/plan/review", ['decision' => 'changes_requested', 'note' => 'أضف مرحلة للتقييم النهائي'])
        ->assertOk()->assertJsonPath('data.follow_up.plan_status', 'changes_requested');
    expect(followUpSent($this->owner, 'plan_changes_requested'))->toBeTrue();

    $this->actingAs($this->owner);
    $this->postJson("{$this->ownerBase}/milestones", ['title' => 'التقييم النهائي', 'deliverable' => 'تقرير تقييم', 'due_at' => now()->addMonths(6)->toDateString(), 'budget_share' => 40])->assertCreated();
    $this->postJson("{$this->ownerBase}/plan/submit")->assertOk();

    $this->actingAs($this->officer);
    $data = $this->postJson("{$this->adminBase}/plan/review", ['decision' => 'approve'])->assertOk()->json('data');
    expect($data['follow_up']['plan_status'])->toBe('approved')
        ->and($data['follow_up']['plan_version'])->toBe(1)
        ->and($data['budget_share_total'])->toEqual(100)
        ->and(collect($data['history'])->pluck('action'))->toContain('follow_up.plan_approved', 'follow_up.plan_changes_requested', 'follow_up.plan_submitted', 'milestone.created');
    expect(followUpSent($this->owner, 'plan_approved'))->toBeTrue();

    // A revision reopens the plan; it needs a new approval.
    $this->actingAs($this->owner);
    $this->postJson("{$this->ownerBase}/plan/revise")->assertOk()->assertJsonPath('data.follow_up.plan_status', 'draft');
    $this->assertDatabaseHas('audit_logs', ['action' => 'follow_up.plan_revision_started', 'user_id' => $this->owner->id]);
});

it('marks a milestone done with evidence through the document pipeline and lets staff reopen it (PRJ-01)', function (): void {
    $milestone = followUpApprovedPlan($this);

    $this->actingAs($this->owner);
    $this->post("{$this->ownerBase}/milestones/{$milestone['id']}/complete", ['note' => 'تم الإطلاق'], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['file']);
    $done = $this->post("{$this->ownerBase}/milestones/{$milestone['id']}/complete", ['note' => 'تم الإطلاق', 'file' => followUpPdf()], ['Accept' => 'application/json'])
        ->assertOk()->json('data');
    expect($done['status'])->toBe('done')->and($done['evidence'])->toHaveCount(1);
    $document = Document::query()->where('uuid', $done['evidence'][0]['uuid'])->firstOrFail();
    expect($document->documentable_type)->toBe('milestone')->and($document->kind->value)->toBe('progress_evidence');
    expect(followUpSent($this->officer, 'milestone_completed'))->toBeTrue();

    // Milestone evidence is internal: staff and owner open it, the connected investor cannot.
    $this->actingAs($this->officer);
    $this->getJson("/api/v1/admin/follow-up/documents/{$document->uuid}/download-url")->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']]);
    $this->actingAs($this->investor);
    $this->getJson("/api/v1/investor/portfolio/documents/{$document->uuid}/download-url")->assertForbidden();

    $this->actingAs($this->officer);
    $this->postJson("{$this->adminBase}/milestones/{$milestone['id']}/reopen", [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $data = $this->postJson("{$this->adminBase}/milestones/{$milestone['id']}/reopen", ['reason' => 'الدليل لا يثبت الإطلاق'])->assertOk()->json('data');
    expect($data['milestones_list'][0]['status'])->toBe('in_progress');
    expect(followUpSent($this->owner, 'milestone_reopened'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'milestone.reopened', 'user_id' => $this->officer->id]);
});

it('takes periodic progress reports for open periods and runs the staff review loop (PRJ-02)', function (): void {
    $milestone = followUpApprovedPlan($this);
    $this->actingAs($this->owner);
    $periods = collect($this->getJson($this->ownerBase)->assertOk()->json('data.periods'));
    $first = $periods->last();
    expect($first['state'])->toBe('due')->and($periods->firstWhere('state', 'current'))->not->toBeNull();

    $payload = [
        'period_start' => $first['period_start'], 'narrative' => 'ملخص الفترة الأولى من التنفيذ', 'achieved' => 'إطلاق النسخة الأولى',
        'risks' => 'تأخر المورد', 'spend_amount' => 125000, 'execution_status' => 'on_track',
        'milestone_updates' => [['milestone_id' => $milestone['id'], 'status' => 'in_progress', 'note' => 'بدأ التنفيذ']],
        'files' => [followUpPdf('report-1.pdf'), followUpPdf('report-2.pdf')],
    ];
    $this->post("{$this->ownerBase}/reports", ['period_start' => '2001-01-01'] + $payload, ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['period_start']);
    $report = $this->post("{$this->ownerBase}/reports", $payload, ['Accept' => 'application/json'])->assertCreated()->json('data');
    expect($report['status'])->toBe('submitted')->and($report['attachments'])->toHaveCount(2)->and($report['due_on'])->toBe($first['due_on']);
    $this->post("{$this->ownerBase}/reports", $payload, ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['period_start']);
    expect(followUpSent($this->officer, 'report_submitted'))->toBeTrue();
    $this->assertDatabaseHas('milestones', ['id' => $milestone['id'], 'status' => 'in_progress']);

    // Investors only see accepted reports.
    $this->actingAs($this->investor);
    expect($this->getJson("/api/v1/investor/portfolio/{$this->opportunity->uuid}")->assertOk()->json('data.reports'))->toBeEmpty();

    $this->actingAs($this->officer);
    $this->postJson("{$this->adminBase}/reports/{$report['id']}/review", ['decision' => 'accept-ish'])->assertStatus(422)->assertJsonValidationErrors(['decision']);
    $this->postJson("{$this->adminBase}/reports/{$report['id']}/review", ['decision' => 'changes_requested', 'note' => 'أرفق كشف المصروفات'])->assertOk();
    expect(followUpSent($this->owner, 'report_changes_requested'))->toBeTrue();

    $this->actingAs($this->owner);
    $this->post("{$this->ownerBase}/reports/{$report['id']}/resubmit", collect($payload)->except(['period_start', 'files'])->all() + ['files' => [followUpPdf('expenses.pdf')]], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.status', 'submitted');

    $this->actingAs($this->officer);
    $this->postJson("{$this->adminBase}/reports/{$report['id']}/review", ['decision' => 'accept'])->assertOk();
    expect(followUpSent($this->owner, 'report_accepted'))->toBeTrue();
    foreach (['progress_report.submitted', 'progress_report.changes_requested', 'progress_report.resubmitted', 'progress_report.accepted'] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action, 'auditable_type' => 'progress_report', 'auditable_id' => $report['id']]);
    }

    $this->actingAs($this->investor);
    $seen = $this->getJson("/api/v1/investor/portfolio/{$this->opportunity->uuid}")->assertOk()->json('data.reports.0');
    expect($seen['narrative'])->toBe('ملخص الفترة الأولى من التنفيذ')->and($seen['review_note'])->toBeNull()->and($seen['attachments'])->toHaveCount(3);
    $this->getJson("/api/v1/investor/portfolio/documents/{$seen['attachments'][0]['uuid']}/download-url")->assertOk();
});

it('verifies indicator values and shows only verified ones, with progress towards the target (IMP-01/02)', function (): void {
    $this->actingAs($this->owner);
    $url = "{$this->ownerBase}/indicators/{$this->indicator->id}/values";
    $data = $this->post($url, ['period_start' => now()->subMonths(3)->toDateString(), 'period_end' => now()->subDay()->toDateString(), 'value' => 600, 'note' => 'من سجلات الحضور', 'file' => followUpPdf('attendance.pdf')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    $valueId = $data['values'][0]['id'];
    expect($data['values'][0]['status'])->toBe('entered')->and($data['values'][0]['evidence'])->not->toBeNull()->and($data['progress_percent'])->toBeNull();
    $this->post($url, ['period_start' => now()->subMonths(3)->toDateString(), 'period_end' => now()->subDay()->toDateString(), 'value' => 610], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['period_end']);
    $this->post($url, ['period_start' => now()->toDateString(), 'period_end' => now()->addMonth()->toDateString(), 'value' => 1], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['period_end']);
    expect(followUpSent($this->officer, 'indicator_submitted'))->toBeTrue();

    $this->actingAs($this->investor);
    expect($this->getJson("/api/v1/investor/portfolio/{$this->opportunity->uuid}")->json('data.indicators.0.values'))->toBeEmpty();

    $this->actingAs($this->officer);
    $review = "{$this->adminBase}/indicator-values/{$valueId}/review";
    $this->postJson($review, ['decision' => 'needs_clarification'])->assertStatus(422)->assertJsonValidationErrors(['note']);
    $this->postJson($review, ['decision' => 'needs_clarification', 'note' => 'العدد لا يطابق السجل المرفق'])->assertOk();
    expect(followUpSent($this->owner, 'indicator_needs_clarification'))->toBeTrue();

    $this->actingAs($this->owner);
    $this->post("{$this->ownerBase}/indicator-values/{$valueId}/resubmit", ['period_start' => now()->subMonths(3)->toDateString(), 'period_end' => now()->subDay()->toDateString(), 'value' => 580], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.values.0.status', 'entered');

    $this->actingAs($this->officer);
    $detail = $this->postJson($review, ['decision' => 'verify'])->assertOk()->json('data');
    expect($detail['indicators'][0]['progress_percent'])->toEqual(48)
        ->and($detail['indicators'][0]['latest_verified']['value'])->toEqual(580);
    $this->assertDatabaseHas('audit_logs', ['action' => 'indicator_value.verified', 'user_id' => $this->officer->id]);
    expect(followUpSent($this->owner, 'indicator_verified'))->toBeTrue();

    $this->actingAs($this->investor);
    $seen = $this->getJson("/api/v1/investor/portfolio/{$this->opportunity->uuid}")->json('data.indicators.0');
    expect($seen['values'])->toHaveCount(1)->and($seen['values'][0]['evidence'])->toBeNull()->and($seen['values'][0]['review_note'])->toBeNull()->and($seen['progress_percent'])->toEqual(48);
});

it('computes the execution status, escalates a stalled project and honours a staff override with a reason (PRJ-03)', function (): void {
    followUpApprovedPlan($this, -10); // milestone already 10 days late
    expect($this->opportunity->refresh()->execution_status?->value)->toBe('delayed');
    expect(followUpSent($this->owner, 'status_changed'))->toBeTrue();

    $this->actingAs($this->officer);
    $this->putJson("{$this->adminBase}/status", ['status' => 'on_track'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $data = $this->putJson("{$this->adminBase}/status", ['status' => 'on_track', 'reason' => 'التأخير بسبب ترخيص حكومي معلّق'])->assertOk()->json('data');
    expect($data['execution_status'])->toBe('on_track')->and($data['status_overridden'])->toBeTrue()->and($data['follow_up']['computed_status'])->toBe('delayed');
    $this->assertDatabaseHas('audit_logs', ['action' => 'follow_up.status_overridden', 'user_id' => $this->officer->id]);

    // Owner and connected investor both see the effective status.
    $this->actingAs($this->investor);
    $this->getJson("/api/v1/investor/portfolio/{$this->opportunity->uuid}")->assertOk()->assertJsonPath('data.execution_status', 'on_track')->assertJsonMissingPath('data.follow_up.override_reason');
    $this->actingAs($this->owner);
    $this->getJson($this->ownerBase)->assertJsonPath('data.follow_up.override_reason', 'التأخير بسبب ترخيص حكومي معلّق');
    $this->putJson("{$this->ownerBase}/remediation", ['remediation_plan' => 'متابعة الترخيص أسبوعيًا مع الجهة المختصة'])->assertOk()->assertJsonPath('data.follow_up.remediation_plan', 'متابعة الترخيص أسبوعيًا مع الجهة المختصة');

    $this->actingAs($this->officer);
    $this->putJson("{$this->adminBase}/status", ['status' => null])->assertOk()->assertJsonPath('data.execution_status', 'delayed');

    // 50 days later the milestone is 60 days late (> 45) and the first report is overdue → stalled + escalation.
    $this->travel(50)->days();
    $this->artisan('sciis:follow-up-reminders')->assertSuccessful();
    expect($this->opportunity->refresh()->execution_status?->value)->toBe('stalled')
        ->and(FollowUp::query()->where('opportunity_id', $this->opportunity->id)->value('escalated_at'))->not->toBeNull();
    expect(followUpSent($this->officer, 'stalled_escalation'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'follow_up.escalated', 'auditable_id' => $this->opportunity->id]);

    $stats = $this->getJson('/api/v1/admin/follow-up')->assertOk()->json('meta.stats');
    expect($stats['stalled'])->toBe(1)->and($stats['overdue_reports'])->toBeGreaterThanOrEqual(1);
});

it('reminds the owner before and after a report due date, once per threshold (PRJ-02)', function (): void {
    $this->artisan('sciis:follow-up-reminders')->assertSuccessful();
    expect(followUpSent($this->owner, 'report_due'))->toBeTrue();
    $count = fn (): int => Notification::sent($this->owner, FollowUpNotification::class)->filter(fn (FollowUpNotification $n): bool => in_array($n->key(), ['report_due', 'report_overdue'], true))->count();
    $first = $count();

    $this->artisan('sciis:follow-up-reminders')->assertSuccessful();
    expect($count())->toBe($first);

    $this->travel(25)->days();
    $this->artisan('sciis:follow-up-reminders')->assertSuccessful();
    expect(followUpSent($this->owner, 'report_overdue'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'follow_up.report_reminder_sent']);
});

it('sends owner updates to connected investors, or holds them for review when the setting asks for it (ENT-10)', function (): void {
    $other = $this->approvedInvestor();
    $this->actingAs($this->owner);
    $update = $this->post("{$this->ownerBase}/updates", ['title' => 'تحديث الربع الأول', 'body' => 'أطلقنا الخدمة في مدينتين جديدتين.', 'file' => followUpPdf('q1.pdf')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    expect($update['status'])->toBe('published')->and($update['attachments'])->toHaveCount(1);
    expect(followUpSent($this->investor, 'update_posted'))->toBeTrue()->and(followUpSent($other, 'update_posted'))->toBeFalse();

    $this->actingAs($this->investor);
    $seen = $this->getJson("/api/v1/investor/portfolio/{$this->opportunity->uuid}")->json('data.updates.0');
    expect($seen['title'])->toBe('تحديث الربع الأول');
    $this->getJson("/api/v1/investor/portfolio/documents/{$seen['attachments'][0]['uuid']}/download-url")->assertOk();
    $this->actingAs($other);
    $this->getJson("/api/v1/investor/portfolio/documents/{$seen['attachments'][0]['uuid']}/download-url")->assertForbidden();

    app(SettingsService::class)->set('follow_up.updates_require_review', true);
    $this->actingAs($this->owner);
    $held = $this->postJson("{$this->ownerBase}/updates", ['body' => 'تحديث بانتظار المراجعة قبل الإرسال.'])->assertCreated()->json('data');
    expect($held['status'])->toBe('pending_review')->and(followUpSent($this->officer, 'update_pending_review'))->toBeTrue();
    $this->actingAs($this->investor);
    expect($this->getJson("/api/v1/investor/portfolio/{$this->opportunity->uuid}")->json('data.updates'))->toHaveCount(1);

    $this->actingAs($this->officer);
    $this->postJson("{$this->adminBase}/updates/{$held['id']}/review", ['decision' => 'publish'])->assertOk();
    expect(followUpSent($this->owner, 'update_published'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'investor_update.published', 'auditable_id' => $held['id']]);
    $this->actingAs($this->investor);
    expect($this->getJson("/api/v1/investor/portfolio/{$this->opportunity->uuid}")->json('data.updates'))->toHaveCount(2);
});

it('lists linked projects only for connected investors and keeps follow-up endpoints behind ownership and impact.verify (INV-06)', function (): void {
    followUpApprovedPlan($this);

    $this->actingAs($this->investor);
    $rows = $this->getJson('/api/v1/investor/portfolio')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['uuid'])->toBe($this->opportunity->uuid)->and($rows[0]['milestones']['total'])->toBe(1)
        ->and($rows[0])->not->toHaveKey('pending')->and($rows[0])->not->toHaveKey('owner');

    $stranger = $this->approvedInvestor();
    $this->actingAs($stranger);
    expect($this->getJson('/api/v1/investor/portfolio')->assertOk()->json('data'))->toBeEmpty();
    $this->getJson("/api/v1/investor/portfolio/{$this->opportunity->uuid}")->assertNotFound();

    // A closed interest no longer opens the project.
    Interest::query()->where('investor_user_id', $this->investor->id)->update(['status' => InterestStatus::Closed->value]);
    $this->actingAs($this->investor);
    $this->getJson("/api/v1/investor/portfolio/{$this->opportunity->uuid}")->assertNotFound();

    $this->actingAs($this->entrepreneur());
    $this->getJson($this->ownerBase)->assertForbidden();
    $this->actingAs($this->staff('evaluator'));
    $this->getJson('/api/v1/admin/follow-up')->assertForbidden();
    $this->postJson("{$this->adminBase}/plan/review", ['decision' => 'approve'])->assertForbidden();

    $this->actingAs($this->owner);
    $this->getJson('/api/v1/entrepreneur/follow-up')->assertOk()->assertJsonPath('data.0.uuid', $this->opportunity->uuid)->assertJsonPath('data.0.owner', null);

    $draft = Opportunity::factory()->create(['owner_user_id' => $this->owner->id]);
    $this->getJson("/api/v1/entrepreneur/follow-up/{$draft->uuid}")->assertStatus(422)->assertJsonPath('code', 'not_in_follow_up');
});
