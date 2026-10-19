<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\EvaluationCriteriaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, TaxonomySeeder::class, EvaluationCriteriaSeeder::class, SettingsSeeder::class]);
    }

    /** Routes are guarded by `auth:sanctum`, so authenticate through Sanctum's guard (bearer semantics). */
    public function actingAs(UserContract $user, $guard = null)
    {
        if ($guard === null || in_array($guard, ['api', 'sanctum'], true)) {
            Sanctum::actingAs($user, ['*']);

            return $this;
        }

        return parent::actingAs($user, $guard);
    }

    protected function staff(string ...$roles): User
    {
        return User::factory()->staff(...$roles)->create();
    }

    protected function approvedInvestor(): User
    {
        return User::factory()->approvedMember()->create();
    }

    protected function registeredInvestor(): User
    {
        $user = User::factory()->investor()->create();
        Membership::query()->updateOrCreate(['user_id' => $user->id], ['status' => MembershipStatus::Registered]);

        return $user;
    }

    protected function entrepreneur(): User
    {
        return User::factory()->entrepreneur()->create();
    }
}
