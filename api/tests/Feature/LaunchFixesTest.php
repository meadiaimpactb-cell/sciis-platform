<?php

declare(strict_types=1);

use App\Enums\OpportunityStatus;
use App\Models\EvaluationAssignment;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\EvaluationService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
});

it('emails a reset link that opens the existing reset page, in the user language', function (): void {
    Notification::fake();
    config(['app.frontend_url' => 'https://sciis.org']);
    $ar = User::factory()->investor()->create(['email' => 'ar@sciis.test', 'locale' => 'ar']);
    $en = User::factory()->investor()->create(['email' => 'en@sciis.test', 'locale' => 'en']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ar@sciis.test'])->assertOk();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'en@sciis.test'])->assertOk();

    Notification::assertSentTo($ar, ResetPassword::class, function (ResetPassword $n) use ($ar): bool {
        return str_starts_with($n->toMail($ar)->actionUrl, 'https://sciis.org/auth/reset?token=');
    });
    Notification::assertSentTo($en, ResetPassword::class, function (ResetPassword $n) use ($en): bool {
        return str_starts_with($n->toMail($en)->actionUrl, 'https://sciis.org/en/auth/reset?token=');
    });
});

it('lets a staff member with a custom role into the admin area, limited by permissions', function (): void {
    $role = Role::findOrCreate('communications_officer', 'api');
    $role->syncPermissions([Permission::findOrCreate('reports.view', 'api')]);
    $user = User::factory()->staff()->create();
    $user->assignRole($role);

    $this->actingAs($user, 'api');
    $this->getJson('/api/v1/admin/dashboard')->assertOk();
    $this->getJson('/api/v1/admin/users')->assertForbidden();
});

it('keeps non-staff and role-less staff out of the admin area', function (): void {
    $this->actingAs($this->approvedInvestor(), 'api');
    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();

    $this->actingAs(User::factory()->staff()->create(), 'api');
    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

it('shows an evaluator their assignment before the conflict-of-interest declaration', function (): void {
    $reviewer = $this->staff('reviewer');
    $evaluatorA = $this->staff('evaluator');
    $evaluatorB = $this->staff('evaluator');
    $outsider = $this->staff('evaluator');
    $opportunity = Opportunity::factory()->status(OpportunityStatus::UnderReview)->create();
    app(EvaluationService::class)->assign($opportunity, [$evaluatorA->id, $evaluatorB->id], $reviewer);
    $assignment = EvaluationAssignment::query()->where('evaluator_user_id', $evaluatorA->id)->firstOrFail();

    $this->actingAs($outsider, 'api');
    $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}/my-assignment")->assertNotFound();

    $this->actingAs($evaluatorA, 'api');
    $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}")->assertForbidden();
    $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}/my-assignment")->assertOk()
        ->assertJsonPath('data.assignment.id', $assignment->id)
        ->assertJsonPath('data.assignment.coi_declared_at', null)
        ->assertJsonPath('data.opportunity.name', $opportunity->name);

    $this->postJson("/api/v1/admin/evaluation-assignments/{$assignment->id}/coi", ['has_conflict' => false])->assertOk();
    $this->getJson("/api/v1/admin/opportunities/{$opportunity->uuid}")->assertOk();
});

it('requires only the email code while phone verification is switched off', function (): void {
    $user = User::factory()->investor()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);
    config(['sciis.verification.optional' => false]);

    config(['sciis.verification.phone' => true]);
    expect($user->isFullyVerified())->toBeFalse();
    $this->actingAs($user, 'api')->getJson('/api/v1/investor/dashboard')->assertForbidden()->assertJsonPath('code', 'verification_required');

    config(['sciis.verification.phone' => false]);
    expect($user->fresh()->isFullyVerified())->toBeTrue();
    $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.phone_verification', false);

    $unverified = User::factory()->investor()->create(['email_verified_at' => null, 'phone_verified_at' => null]);
    expect($unverified->isFullyVerified())->toBeFalse();
});
