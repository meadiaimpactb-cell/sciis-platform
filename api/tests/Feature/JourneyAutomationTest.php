<?php

declare(strict_types=1);

use App\Enums\ConsentType;
use App\Enums\EvaluationResult;
use App\Enums\OpportunityStatus;
use App\Models\CompletionRequest;
use App\Models\Consent;
use App\Models\EvaluationAssignment;
use App\Models\Interest;
use App\Models\Opportunity;
use App\Models\OpportunityStatusTransition;
use App\Models\Teaser;
use App\Models\User;
use App\Notifications\CompletionReminderNotification;
use App\Notifications\EvaluationDivergenceNotification;
use App\Notifications\OpportunityStatusChangedNotification;
use App\Notifications\SlaOverdueNotification;
use App\Services\EvaluationService;
use App\Services\OpportunityStateMachine;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/** Journey 11.3 (completion reminders + auto-close), ADM-03 (post-evaluation SLA + overdue owner decisions), EVL-05 (divergence). */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->owner = $this->entrepreneur();
});

function pendingCompletion(Opportunity $opportunity, Carbon $due): CompletionRequest
{
    return CompletionRequest::query()->create(['opportunity_id' => $opportunity->id, 'due_at' => $due, 'items' => [['document_kind' => 'pitch_deck', 'note' => 'نسخة محدّثة']]]);
}

it('reminds the owner 3 days before and on the due date, once per threshold', function (): void {
    Notification::fake();
    $opportunity = Opportunity::factory()->status(OpportunityStatus::NeedsCompletion)->create(['owner_user_id' => $this->owner->id]);
    $request = pendingCompletion($opportunity, now()->addDays(3)->setTime(12, 0));

    $this->artisan('sciis:completion-follow-up')->assertSuccessful();
    $this->artisan('sciis:completion-follow-up')->assertSuccessful(); // same day → no duplicate
    Notification::assertSentToTimes($this->owner, CompletionReminderNotification::class, 1);
    expect($request->refresh()->reminders_sent)->toBe([3]);

    $this->travel(2)->days();
    $this->artisan('sciis:completion-follow-up')->assertSuccessful(); // 1 day left: the 3-day reminder was already sent
    Notification::assertSentToTimes($this->owner, CompletionReminderNotification::class, 1);

    $this->travel(1)->days();
    $this->artisan('sciis:completion-follow-up')->assertSuccessful(); // due today
    Notification::assertSentToTimes($this->owner, CompletionReminderNotification::class, 2);
    expect($request->refresh()->reminders_sent)->toBe([3, 0])
        ->and($opportunity->refresh()->status)->toBe(OpportunityStatus::NeedsCompletion);
});

it('uses the configured reminder days', function (): void {
    Notification::fake();
    app(SettingsService::class)->set('sla.completion_reminder_days', [7]);
    $opportunity = Opportunity::factory()->status(OpportunityStatus::NeedsCompletion)->create(['owner_user_id' => $this->owner->id]);
    pendingCompletion($opportunity, now()->addDays(3));

    $this->artisan('sciis:completion-follow-up')->assertSuccessful();
    Notification::assertSentToTimes($this->owner, CompletionReminderNotification::class, 1);

    $this->travel(3)->days();
    $this->artisan('sciis:completion-follow-up')->assertSuccessful(); // 0 is not configured
    Notification::assertSentToTimes($this->owner, CompletionReminderNotification::class, 1);
});

it('auto-closes a needs_completion opportunity once the completion is overdue by the configured days', function (): void {
    Notification::fake();
    $opportunity = Opportunity::factory()->status(OpportunityStatus::NeedsCompletion)->create(['owner_user_id' => $this->owner->id, 'reference_no' => 'A-2026-0001']);
    $request = pendingCompletion($opportunity, now()->subDays(13));
    $eligible = Opportunity::factory()->eligible()->create();
    pendingCompletion($eligible, now()->subDays(40)); // only needs_completion files are auto-closed

    $this->artisan('sciis:completion-follow-up')->assertSuccessful();
    expect($opportunity->refresh()->status)->toBe(OpportunityStatus::NeedsCompletion);

    $this->travel(1)->days();
    $this->artisan('sciis:completion-follow-up')->assertSuccessful();

    expect($opportunity->refresh()->status)->toBe(OpportunityStatus::Closed)
        ->and($request->refresh()->resolved_at)->not->toBeNull()
        ->and($eligible->refresh()->status)->toBe(OpportunityStatus::Eligible);

    $transition = OpportunityStatusTransition::query()->where('opportunity_id', $opportunity->id)->latest('id')->firstOrFail();
    expect($transition->from_status)->toBe(OpportunityStatus::NeedsCompletion)
        ->and($transition->actor_user_id)->toBeNull()
        ->and($transition->reason)->not->toBeEmpty()
        ->and($transition->metadata['system'])->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'opportunity.auto_closed', 'auditable_id' => $opportunity->id]);
    Notification::assertSentTo($this->owner, OpportunityStatusChangedNotification::class);

    // The owner's timeline shows the council as the actor, with the reason.
    $this->actingAs($this->owner, 'api');
    $timeline = $this->getJson("/api/v1/entrepreneur/opportunities/{$opportunity->uuid}")->assertOk()->json('data.timeline');
    expect(end($timeline)['to_status'])->toBe('closed')->and(end($timeline)['reason'])->toBe($transition->reason);
});

it('does not auto-close when the setting is 0', function (): void {
    app(SettingsService::class)->set('sla.completion_auto_close_days', 0);
    $opportunity = Opportunity::factory()->status(OpportunityStatus::NeedsCompletion)->create(['owner_user_id' => $this->owner->id]);
    pendingCompletion($opportunity, now()->subDays(60));

    $this->artisan('sciis:completion-follow-up')->assertSuccessful();
    expect($opportunity->refresh()->status)->toBe(OpportunityStatus::NeedsCompletion);
});

it('sets a stage SLA after evaluation and reminds staff of overdue stages and owner decisions (ADM-03)', function (): void {
    Notification::fake();
    $matching = $this->staff('matching_officer');
    $admin = $this->staff('super_admin');
    $reviewer = $this->staff('reviewer');

    // Entering eligible starts the matching SLA (sla.matching_days).
    app(SettingsService::class)->set('sla.matching_days', 10);
    $opportunity = Opportunity::factory()->status(OpportunityStatus::UnderReview)->create(['owner_user_id' => $this->owner->id, 'evaluation_result' => EvaluationResult::Eligible]);
    Teaser::query()->create(['opportunity_id' => $opportunity->id, 'summary_ar' => 'ملخص تعريفي للفرصة', 'highlights' => [], 'version' => 1, 'approved_by_owner_at' => now(), 'approved_by_council_at' => now()]);
    Consent::query()->create(['user_id' => $this->owner->id, 'type' => ConsentType::TeaserPublication, 'version' => '1.0', 'text_hash' => 'x', 'accepted_at' => now(), 'subject_type' => $opportunity->getMorphClass(), 'subject_id' => $opportunity->id]);
    $opportunity = app(OpportunityStateMachine::class)->transition($opportunity, OpportunityStatus::Eligible, $admin);
    expect($opportunity->stage_due_at->toDateString())->toBe(now()->addDays(10)->toDateString())
        ->and($opportunity->slaDueAt()?->toDateString())->toBe($opportunity->stage_due_at->toDateString());

    // An access request past its owner-decision deadline.
    $interest = Interest::factory()->create(['owner_decision_due_at' => now()->addDays(2)]);

    $this->artisan('sciis:sla-reminders')->assertSuccessful();
    Notification::assertNotSentTo([$matching, $admin], SlaOverdueNotification::class);

    $this->travel(11)->days();
    expect($opportunity->refresh()->overdueKind())->toBe('matching')
        ->and(Opportunity::query()->overdue()->pluck('id'))->toContain($opportunity->id);

    $this->artisan('sciis:sla-reminders')->assertSuccessful();

    Notification::assertSentTo($matching, SlaOverdueNotification::class, function (SlaOverdueNotification $n) use ($matching, $opportunity, $interest): bool {
        $kinds = collect($n->toArray($matching)['items'])->pluck('kind', 'uuid');

        return $kinds[$opportunity->uuid] === 'matching' && $kinds[$interest->opportunity->uuid] === 'owner_decision';
    });
    Notification::assertSentTo($admin, SlaOverdueNotification::class);
    Notification::assertNotSentTo($reviewer, SlaOverdueNotification::class);
});

it('flags divergence with the configured threshold and alerts supervisors when the second evaluation lands (EVL-05)', function (): void {
    Notification::fake();
    app(SettingsService::class)->set('evaluation.score_diff_threshold', 3);
    $service = app(EvaluationService::class);
    $opportunity = Opportunity::factory()->status(OpportunityStatus::UnderReview)->create();
    $reviewer = $this->staff('reviewer');
    $supervisor = $this->staff('super_admin');
    [$a, $b] = [$this->staff('evaluator'), $this->staff('evaluator')];
    $service->assign($opportunity, [$a->id, $b->id], $reviewer);
    $criteria = $service->activeCriteria();
    $first = $criteria->first()->key;
    $second = $criteria->skip(1)->first()->key;

    $score = function ($evaluator, array $overrides) use ($service, $criteria): void {
        $assignment = EvaluationAssignment::query()->where('evaluator_user_id', $evaluator->id)->firstOrFail();
        $service->declareCoi($assignment, $evaluator, false);
        $scores = $criteria->mapWithKeys(fn ($c) => [$c->key => ['score' => $overrides[$c->key] ?? 3]])->all();
        $service->score($assignment->refresh(), $evaluator, $scores, EvaluationResult::Eligible, null);
    };

    $score($a, [$first => 1, $second => 2]);
    Notification::assertNothingSent(); // one evaluation only

    $score($b, [$first => 5, $second => 4]); // deltas 4 (flagged at 3) and 2 (not flagged)

    $panel = $service->comparison($opportunity);
    expect($panel['diff_threshold'])->toBe(3)
        ->and(collect($panel['diffs'])->pluck('criterion_key')->all())->toBe([$first]);

    Notification::assertSentTo($supervisor, EvaluationDivergenceNotification::class, fn (EvaluationDivergenceNotification $n): bool => $n->via($supervisor) === ['database', 'mail']
        && collect($n->toArray($supervisor)['criteria'])->pluck('delta')->all() === [4]);
    Notification::assertNotSentTo($a, EvaluationDivergenceNotification::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'evaluation.divergence_flagged', 'auditable_id' => $opportunity->id]);
});

it('does not alert when no criterion reaches the threshold', function (): void {
    Notification::fake();
    $service = app(EvaluationService::class);
    $opportunity = Opportunity::factory()->status(OpportunityStatus::UnderReview)->create();
    [$a, $b] = [$this->staff('evaluator'), $this->staff('evaluator')];
    $this->staff('super_admin');
    $service->assign($opportunity, [$a->id, $b->id], $this->staff('reviewer'));
    foreach ([[$a, 3], [$b, 4]] as [$evaluator, $value]) {
        $assignment = EvaluationAssignment::query()->where('evaluator_user_id', $evaluator->id)->firstOrFail();
        $service->declareCoi($assignment, $evaluator, false);
        $service->score($assignment->refresh(), $evaluator, $service->activeCriteria()->mapWithKeys(fn ($c) => [$c->key => ['score' => $value]])->all(), EvaluationResult::Eligible, null);
    }

    Notification::assertNothingSentTo(User::query()->role('super_admin')->get());
    expect($service->comparison($opportunity)['diffs'])->toBe([]);
});

it('validates and saves the new settings keys through the admin settings API', function (): void {
    $admin = $this->staff('super_admin');
    $this->actingAs($admin, 'api');

    $keys = collect($this->getJson('/api/v1/admin/settings')->assertOk()->json('data.settings'))->pluck('key');
    expect($keys)->toContain('sla.matching_days', 'sla.connection_days', 'sla.negotiation_days', 'sla.completion_reminder_days', 'sla.completion_auto_close_days', 'evaluation.score_diff_threshold');

    $this->putJson('/api/v1/admin/settings', ['settings' => ['evaluation.score_diff_threshold' => 9]])->assertStatus(422);
    $this->putJson('/api/v1/admin/settings', ['settings' => ['sla.completion_reminder_days' => [5, 'x']]])->assertStatus(422);
    $this->putJson('/api/v1/admin/settings', ['settings' => ['sla.completion_reminder_days' => [5, 1], 'evaluation.score_diff_threshold' => 1]])->assertOk();

    expect(app(SettingsService::class)->completionReminderDays())->toBe([5, 1])
        ->and(app(SettingsService::class)->scoreDiffThreshold())->toBe(1);
});
