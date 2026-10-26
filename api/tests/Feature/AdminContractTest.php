<?php

declare(strict_types=1);

use App\Enums\OpportunityStatus;
use App\Models\Interest;
use App\Models\Opportunity;
use App\Models\TaxonomyTerm;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/** Admin API ⇄ web admin portal contract (audit items #16–#31, ACC-03). */
beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->admin = $this->staff('super_admin');
});

it('returns the dashboard keys the web expects', function (): void {
    Opportunity::factory()->status(OpportunityStatus::New)->create();
    $this->actingAs($this->admin, 'api');

    $data = $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonStructure(['data' => [
            'today' => ['new', 'overdue', 'pending_memberships', 'interest_rate'],
            'pipeline' => [['status', 'count']],
            'membership_requests', 'queue', 'evaluation_focus', 'candidates_focus', 'my_tasks',
        ]])->json('data');

    expect($data['my_tasks'])->toBeArray()
        ->and($data['queue'][0])->toHaveKeys(['uuid', 'reference_no', 'name', 'status', 'sector', 'owner_name', 'assignee', 'sla_due_at', 'sla_days_left', 'is_overdue', 'submitted_at', 'updated_at'])
        ->and($data['today']['new'])->toBe(1);

    $task = collect($data['my_tasks'])->firstWhere('kind', 'review');
    expect($task)->toHaveKeys(['id', 'title', 'href', 'due_at']);
});

it('accepts filter[...] and top-level filters on /admin/opportunities (sector by key or id)', function (): void {
    $sector = TaxonomyTerm::query()->where('group', 'sectors')->orderBy('id')->first();
    $other = TaxonomyTerm::query()->where('group', 'sectors')->orderByDesc('id')->first();
    Opportunity::factory()->status(OpportunityStatus::New)->create(['sector_id' => $sector->id]);
    Opportunity::factory()->status(OpportunityStatus::UnderReview)->create(['sector_id' => $other->id]);
    $this->actingAs($this->admin, 'api');

    expect($this->getJson('/api/v1/admin/opportunities?filter[status]=new')->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/admin/opportunities?status=under_review')->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson("/api/v1/admin/opportunities?filter[sector]={$sector->key}")->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson("/api/v1/admin/opportunities?sector={$other->id}")->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/admin/opportunities?filter[overdue]=1')->assertOk()->json('meta.total'))->toBe(0);
});

it('exposes candidates with an investor alias and nominates by integer id', function (): void {
    $opportunity = Opportunity::factory()->eligible()->create();
    $investor = $this->approvedInvestor();
    $this->actingAs($this->admin, 'api');

    $candidates = $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}/candidates")->assertOk()->json('data');
    expect($candidates)->toHaveKeys(['max_nominations', 'current_nominations', 'candidates'])
        ->and($candidates['candidates'][0]['investor'])->toHaveKeys(['id', 'uuid', 'name', 'type'])
        ->and($candidates['candidates'][0]['matched'])->toHaveKeys(['sector', 'stage', 'region', 'type', 'range']);

    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/nominations", ['investor_user_ids' => [$investor->uuid], 'rationale' => 'ترشيح وفق المعايير المطابقة'])->assertStatus(422);
    $this->postJson("/api/v1/admin/opportunities/{$opportunity->uuid}/nominations", ['investor_user_ids' => [$investor->id], 'rationale' => 'ترشيح وفق المعايير المطابقة'])
        ->assertCreated()->assertJsonPath('data.0.investor.id', $investor->id);
});

it('lists interests with allowed_transitions for staff and filters by opportunity', function (): void {
    $opportunity = Opportunity::factory()->eligible()->create();
    $other = Opportunity::factory()->eligible()->create();
    $investor = $this->approvedInvestor();
    Interest::query()->create(['opportunity_id' => $opportunity->id, 'investor_user_id' => $investor->id, 'status' => 'shared']);
    Interest::query()->create(['opportunity_id' => $other->id, 'investor_user_id' => $investor->id, 'status' => 'sent']);
    $this->actingAs($this->admin, 'api');

    $rows = $this->getJson("/api/v1/admin/interests?filter[opportunity]={$opportunity->uuid}")->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['allowed_transitions'])->toBe(['connected', 'closed'])
        ->and($rows[0]['investor']['id'])->toBe($investor->id);

    $this->postJson("/api/v1/admin/interests/{$rows[0]['id']}/transition", ['to' => 'closed'])->assertStatus(422);
});

it('creates staff without a password and emails a reset link', function (): void {
    Notification::fake();
    $this->actingAs($this->admin, 'api');

    $response = $this->postJson('/api/v1/admin/users', ['name' => 'موظف جديد', 'email' => 'new.staff@sciis.test', 'roles' => ['reviewer']])
        ->assertCreated()->assertJsonPath('password_reset_sent', true)->assertJsonPath('data.roles.0', 'reviewer');

    $user = User::query()->where('email', 'new.staff@sciis.test')->firstOrFail();
    Notification::assertSentTo($user, ResetPassword::class);
    expect($response->json('data.id'))->toBe($user->id);

    // filter[type] and top-level type are both honoured.
    expect($this->getJson('/api/v1/admin/users?filter[type]=staff')->assertOk()->json('meta.total'))->toBe(2)
        ->and($this->getJson('/api/v1/admin/users?type=investor_individual')->assertOk()->json('meta.total'))->toBe(0);
});

it('gives non-managers a slim staff list by role for pickers', function (): void {
    $reviewer = $this->staff('reviewer');
    $this->staff('evaluator');
    $this->actingAs($reviewer, 'api');

    $this->getJson('/api/v1/admin/users')->assertForbidden();
    $rows = $this->getJson('/api/v1/admin/users?role=evaluator')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0])->toHaveKeys(['id', 'name', 'roles'])->not->toHaveKey('email');
});

it('updates settings through PUT {settings:{key:value}} and surfaces is_public', function (): void {
    $this->actingAs($this->admin, 'api');

    $index = $this->getJson('/api/v1/admin/settings')->assertOk()->json('data');
    expect($index)->toHaveKeys(['settings', 'features'])->and($index['settings'][0])->toHaveKeys(['key', 'value', 'group', 'is_public']);

    $this->putJson('/api/v1/admin/settings', ['values' => ['sla.review_days' => 7]])->assertStatus(422);
    $this->putJson('/api/v1/admin/settings', ['settings' => ['sla.review_days' => 7]])->assertOk();
    expect(collect($this->getJson('/api/v1/admin/settings')->json('data.settings'))->firstWhere('key', 'sla.review_days')['value'])->toBe(7);
});

it('returns evaluation criteria with float weights and the current version', function (): void {
    $this->actingAs($this->admin, 'api');
    $response = $this->getJson('/api/v1/admin/evaluation-criteria')->assertOk()->assertJsonStructure(['data', 'version']);
    expect($response->json('data.0.weight'))->toBeFloat();
});

it('returns reports with kind/series/unit and honours sector/region filters', function (): void {
    $sector = TaxonomyTerm::query()->where('group', 'sectors')->orderBy('id')->first();
    Opportunity::factory()->status(OpportunityStatus::New)->create(['sector_id' => $sector->id]);
    Opportunity::factory()->status(OpportunityStatus::New)->create();
    $this->actingAs($this->admin, 'api');

    $report = $this->getJson('/api/v1/admin/reports/opportunities_funnel')->assertOk()->json('data');
    expect($report)->toHaveKeys(['key', 'title', 'columns', 'rows', 'kind', 'series', 'unit', 'period', 'filters', 'generated_at'])
        ->and($report['kind'])->toBe('funnel')
        ->and($report['series'][0])->toBe(['label' => 'submitted', 'value' => 2]);

    $filtered = $this->getJson("/api/v1/admin/reports/opportunities_funnel?filter[sector]={$sector->key}")->assertOk()->json('data');
    expect($filtered['series'][0]['value'])->toBe(1)->and($filtered['filters']['sector'])->toBe($sector->id);

    $rate = $this->getJson('/api/v1/admin/reports/eligibility_rate')->assertOk()->json('data');
    expect($rate['kind'])->toBe('number')->and($rate['unit'])->toBe('%')->and($rate['series'][0]['label'])->toBe('rate_percent');
});

it('filters audit logs by user email and exports them as xlsx', function (): void {
    $this->actingAs($this->admin, 'api');
    $this->getJson('/api/v1/admin/reports/opportunities_funnel')->assertOk(); // creates a report.viewed row

    $byEmail = $this->getJson("/api/v1/admin/audit-logs?filter[user]={$this->admin->email}&filter[action]=report")->assertOk()->json('data');
    expect($byEmail)->not->toBeEmpty()->and($byEmail[0]['user']['id'])->toBe($this->admin->id);
    expect($this->getJson("/api/v1/admin/audit-logs?user={$this->admin->uuid}")->assertOk()->json('meta.total'))->toBeGreaterThan(0);

    $this->get('/api/v1/admin/audit-logs/export.xlsx?filter[action]=report')->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $this->assertDatabaseHas('audit_logs', ['action' => 'audit_log.export']);
});

it('manages roles and permissions (ACC-03)', function (): void {
    $this->actingAs($this->admin, 'api');

    $index = $this->getJson('/api/v1/admin/roles')->assertOk()->json('data');
    expect($index['permissions'])->toContain('reports.view')->and(collect($index['roles'])->pluck('name'))->toContain('super_admin', 'reviewer');

    $created = $this->postJson('/api/v1/admin/roles', ['name' => 'data_analyst', 'permissions' => ['reports.view']])->assertCreated()->json('data');
    expect($created['permissions'])->toBe(['reports.view'])->and($created['is_system'])->toBeFalse();

    $this->patchJson("/api/v1/admin/roles/{$created['id']}", ['permissions' => ['reports.view', 'reports.export']])->assertOk()->assertJsonCount(2, 'data.permissions');
    $super = collect($index['roles'])->firstWhere('name', 'super_admin');
    $this->patchJson("/api/v1/admin/roles/{$super['id']}", ['permissions' => []])->assertStatus(422);

    $this->actingAs($this->staff('reviewer'), 'api');
    $this->getJson('/api/v1/admin/roles')->assertForbidden();
});
