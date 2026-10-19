<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Enums\UserType;
use App\Models\InvestorProfile;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => UserType::InvestorIndividual,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+9665'.fake()->unique()->numerify('########'),
            'password' => static::$password ??= 'Password123!',
            'locale' => 'ar',
            'status' => 'active',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null, 'phone_verified_at' => null]);
    }

    public function staff(string ...$roles): static
    {
        return $this->state(fn (): array => ['type' => UserType::Staff])
            ->afterCreating(function (User $user) use ($roles): void {
                if ($roles !== []) {
                    $user->syncRoles($roles);
                }
            });
    }

    public function entrepreneur(): static
    {
        return $this->state(fn (): array => ['type' => UserType::EntrepreneurIndividual]);
    }

    public function investor(): static
    {
        return $this->state(fn (): array => ['type' => UserType::InvestorIndividual])
            ->afterCreating(function (User $user): void {
                InvestorProfile::query()->firstOrCreate(['user_id' => $user->id]);
                Membership::query()->firstOrCreate(['user_id' => $user->id], ['status' => MembershipStatus::Registered]);
            });
    }

    /** Investor with approved membership + terms accepted (approved_member level). */
    public function approvedMember(): static
    {
        return $this->investor()->afterCreating(function (User $user): void {
            Membership::query()->updateOrCreate(['user_id' => $user->id], [
                'status' => MembershipStatus::Approved, 'submitted_at' => now(), 'approved_at' => now(), 'terms_accepted_at' => now(),
            ]);
        });
    }
}
