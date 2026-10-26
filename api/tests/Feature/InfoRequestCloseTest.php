<?php

declare(strict_types=1);

use App\Enums\InfoRequestStatus;
use App\Models\InfoRequest;
use App\Models\Opportunity;
use App\Notifications\InfoRequestClosedNotification;
use Illuminate\Support\Facades\Notification;

/** §6.3 — the "closed" status of an information request: staff close any open request, the investor their answered one. */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->owner = $this->entrepreneur();
    $this->opportunity = Opportunity::factory()->eligible()->create(['owner_user_id' => $this->owner->id]);
    $this->investor = $this->approvedInvestor();
    $this->matching = $this->staff('matching_officer');
});

function infoRequestFor(Opportunity $opportunity, $investor, InfoRequestStatus $status = InfoRequestStatus::Sent): InfoRequest
{
    return InfoRequest::query()->create([
        'opportunity_id' => $opportunity->id, 'investor_user_id' => $investor->id, 'subject' => 'سؤال عن الإيرادات', 'body' => 'ما مصادر الإيرادات الحالية؟',
        'status' => $status, 'owner_answer' => $status === InfoRequestStatus::Answered ? 'الإجابة' : null, 'answered_at' => $status === InfoRequestStatus::Answered ? now() : null,
    ]);
}

it('lets staff close an unanswered request, notifies the investor and blocks further answers', function (): void {
    Notification::fake();
    $request = infoRequestFor($this->opportunity, $this->investor);

    $this->actingAs($this->matching, 'api');
    $this->postJson("/api/v1/admin/info-requests/{$request->id}/forward")->assertOk();
    $this->postJson("/api/v1/admin/info-requests/{$request->id}/close")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.can_close', false);

    expect($request->refresh()->closed_at)->not->toBeNull()->and($request->closed_by)->toBe($this->matching->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'info_request.closed', 'auditable_id' => $request->id, 'user_id' => $this->matching->id]);
    Notification::assertSentTo($this->investor, InfoRequestClosedNotification::class);

    // Closed: no forward, reply, owner answer or second close.
    $this->postJson("/api/v1/admin/info-requests/{$request->id}/reply", ['answer' => 'رد متأخر من المجلس'])->assertStatus(422)->assertJsonPath('code', 'info_request_closed');
    $this->postJson("/api/v1/admin/info-requests/{$request->id}/close")->assertStatus(422);
    $this->actingAs($this->owner, 'api');
    $this->postJson("/api/v1/entrepreneur/info-requests/{$request->id}/answer", ['answer' => 'إجابة صاحب المشروع'])->assertStatus(422)->assertJsonPath('code', 'info_request_closed');
});

it('requires nominations.manage for the staff close', function (): void {
    $request = infoRequestFor($this->opportunity, $this->investor);
    $this->actingAs($this->staff('evaluator'), 'api');
    $this->postJson("/api/v1/admin/info-requests/{$request->id}/close")->assertForbidden();
});

it('lets the investor close their own request only once it is answered', function (): void {
    Notification::fake();
    $pending = infoRequestFor($this->opportunity, $this->investor, InfoRequestStatus::Processing);
    $answered = infoRequestFor($this->opportunity, $this->investor, InfoRequestStatus::Answered);
    $other = infoRequestFor($this->opportunity, $this->approvedInvestor(), InfoRequestStatus::Answered);

    $this->actingAs($this->investor, 'api');
    $list = collect($this->getJson('/api/v1/investor/info-requests')->assertOk()->json('data'))->keyBy('id');
    expect($list[$answered->id]['can_close'])->toBeTrue()->and($list[$pending->id]['can_close'])->toBeFalse();

    $this->postJson("/api/v1/investor/info-requests/{$pending->id}/close")->assertStatus(422)->assertJsonPath('code', 'info_request_not_answered');
    $this->postJson("/api/v1/investor/info-requests/{$other->id}/close")->assertForbidden();
    $this->postJson("/api/v1/investor/info-requests/{$answered->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

    expect($answered->refresh()->status)->toBe(InfoRequestStatus::Closed);
    Notification::assertNothingSent();
});
