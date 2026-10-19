<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InterestStatus;
use App\Models\Interest;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Interest> */
class InterestFactory extends Factory
{
    protected $model = Interest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'opportunity_id' => Opportunity::factory()->eligible(),
            'investor_user_id' => User::factory()->approvedMember(),
            'status' => InterestStatus::AwaitingOwnerApproval,
            'owner_decision_due_at' => now()->addDays(7),
        ];
    }
}
