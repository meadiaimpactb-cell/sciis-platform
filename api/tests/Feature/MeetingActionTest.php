<?php

declare(strict_types=1);

use App\Enums\MeetingParticipantRole;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\Opportunity;

/** MTG-02 — meeting follow-up actions: done / undone by the assignee or meeting staff, listed on the dashboards. */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->owner = $this->entrepreneur();
    $this->investor = $this->approvedInvestor();
    $this->staffUser = $this->staff('matching_officer');
    $opportunity = Opportunity::factory()->eligible()->create(['owner_user_id' => $this->owner->id]);
    $this->meeting = Meeting::query()->create([
        'opportunity_id' => $opportunity->id, 'title' => 'اجتماع تعارف', 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(),
        'organizer_user_id' => $this->staffUser->id, 'status' => MeetingStatus::Held, 'minutes' => 'محضر الاجتماع',
    ]);
    foreach ([[$this->owner, MeetingParticipantRole::Owner], [$this->investor, MeetingParticipantRole::Investor]] as [$user, $role]) {
        $this->meeting->participants()->create(['user_id' => $user->id, 'role' => $role]);
    }
    $this->investorAction = $this->meeting->actions()->create(['description' => 'إرسال خطاب النوايا', 'owner_user_id' => $this->investor->id, 'due_at' => now()->addWeek()]);
    $this->ownerAction = $this->meeting->actions()->create(['description' => 'مشاركة القوائم المالية', 'owner_user_id' => $this->owner->id, 'due_at' => now()->addDays(3)]);
});

it('lets the assignee mark their action done and undone, with an audit trail', function (): void {
    $this->actingAs($this->investor, 'api');
    $this->patchJson("/api/v1/meeting-actions/{$this->investorAction->id}", ['done' => true])
        ->assertOk()
        ->assertJsonPath('data.is_mine', true);
    expect($this->investorAction->refresh()->done_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'meeting_action.done', 'auditable_id' => $this->investorAction->id, 'user_id' => $this->investor->id]);

    $this->patchJson("/api/v1/meeting-actions/{$this->investorAction->id}", ['done' => false])->assertOk()->assertJsonPath('data.done_at', null);
    $this->assertDatabaseHas('audit_logs', ['action' => 'meeting_action.reopened', 'auditable_id' => $this->investorAction->id]);

    $this->patchJson("/api/v1/meeting-actions/{$this->investorAction->id}", [])->assertStatus(422)->assertJsonValidationErrors(['done']);
});

it('refuses other participants and allows meeting staff', function (): void {
    $this->actingAs($this->owner, 'api');
    $this->patchJson("/api/v1/meeting-actions/{$this->investorAction->id}", ['done' => true])->assertForbidden();

    $this->actingAs($this->staff('evaluator'), 'api');
    $this->patchJson("/api/v1/meeting-actions/{$this->investorAction->id}", ['done' => true])->assertForbidden();

    $this->actingAs($this->staffUser, 'api');
    $this->patchJson("/api/v1/meeting-actions/{$this->investorAction->id}", ['done' => true])->assertOk();
    expect($this->investorAction->refresh()->done_at)->not->toBeNull();
});

it('lists open actions in the investor and owner dashboards and flags them in the meeting lists', function (): void {
    $this->actingAs($this->investor, 'api');
    $tasks = $this->getJson('/api/v1/investor/dashboard')->assertOk()->json('data.tasks');
    expect($tasks)->toHaveCount(1)->and($tasks[0]['id'])->toBe($this->investorAction->id)->and($tasks[0]['meeting']['title'])->toBe('اجتماع تعارف');
    $actions = collect($this->getJson('/api/v1/investor/meetings')->assertOk()->json('data.0.actions'))->keyBy('id');
    expect($actions[$this->investorAction->id]['can_toggle'])->toBeTrue()->and($actions[$this->ownerAction->id]['can_toggle'])->toBeFalse();

    $this->actingAs($this->owner, 'api');
    $required = collect($this->getJson('/api/v1/entrepreneur/dashboard')->assertOk()->json('data.required_now'))->where('type', 'meeting_action')->values();
    expect($required)->toHaveCount(1)->and($required[0]['meeting_action_id'])->toBe($this->ownerAction->id)->and($required[0]['action'])->toBe('meeting_action');

    // Done actions leave the task lists.
    $this->patchJson("/api/v1/meeting-actions/{$this->ownerAction->id}", ['done' => true])->assertOk();
    $required = collect($this->getJson('/api/v1/entrepreneur/dashboard')->json('data.required_now'))->where('type', 'meeting_action');
    expect($required)->toBeEmpty();
});

it('keeps the done state when staff re-record the minutes', function (): void {
    $this->investorAction->forceFill(['done_at' => now()])->save();

    $this->actingAs($this->staffUser, 'api');
    $this->postJson("/api/v1/admin/meetings/{$this->meeting->id}/minutes", [
        'minutes' => 'محضر محدّث للاجتماع',
        'actions' => [
            ['description' => 'إرسال خطاب النوايا', 'owner_user_id' => $this->investor->id],
            ['description' => 'بند جديد', 'owner_user_id' => $this->owner->id],
        ],
    ])->assertOk();

    $kept = MeetingAction::query()->where('meeting_id', $this->meeting->id)->where('description', 'إرسال خطاب النوايا')->firstOrFail();
    $new = MeetingAction::query()->where('meeting_id', $this->meeting->id)->where('description', 'بند جديد')->firstOrFail();
    expect($kept->done_at)->not->toBeNull()->and($new->done_at)->toBeNull();
});
