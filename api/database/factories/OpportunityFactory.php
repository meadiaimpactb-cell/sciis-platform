<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OpportunityStatus;
use App\Enums\ProjectStage;
use App\Enums\RevenueState;
use App\Models\Opportunity;
use App\Models\Teaser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Opportunity> */
class OpportunityFactory extends Factory
{
    protected $model = Opportunity::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory()->entrepreneur(),
            'name' => 'فرصة '.fake()->unique()->word().' (افتراضي)',
            'short_description' => fake()->sentence(10),
            'stage' => ProjectStage::Seed,
            'status' => OpportunityStatus::Draft,
            'is_listed' => true,
            'problem_statement' => 'المشكلة الاجتماعية',
            'solution' => 'الحل المقترح',
            'expected_change' => 'التغيير المتوقع',
            'beneficiaries_desc' => 'الفئات المستهدفة',
            'beneficiaries_target' => 1000,
            'revenue_state' => RevenueState::Early,
            'funding_amount' => 1_000_000,
            'use_of_funds' => [['item' => 'تشغيل', 'percent' => 60], ['item' => 'تقنية', 'percent' => 40]],
            'prior_funding' => false,
            'prior_funding_desc' => 'سري: تفاصيل التمويل السابق',
            'legal_disputes' => false,
            'legal_disputes_desc' => 'سري: تفاصيل النزاعات',
            'license_required' => false,
            'operational_risks' => 'سري: المخاطر التشغيلية التفصيلية',
            'council_relationship' => false,
            'council_relationship_desc' => 'داخلي: علاقة بالمجلس',
            'data_accuracy_declared_at' => now(),
        ];
    }

    public function status(OpportunityStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status, 'submitted_at' => $status === OpportunityStatus::Draft ? null : now()]);
    }

    public function eligible(): static
    {
        return $this->status(OpportunityStatus::Eligible)
            ->state(fn (): array => ['evaluation_result' => 'eligible', 'evaluation_approved_at' => now(), 'reference_no' => sprintf('A-%s-%04d', now()->format('Y'), fake()->unique()->numberBetween(1, 9999))])
            ->afterCreating(function (Opportunity $o): void {
                Teaser::query()->create([
                    'opportunity_id' => $o->id, 'summary_ar' => 'ملخص تعريفي للفرصة', 'highlights' => ['نقطة 1', 'نقطة 2'], 'version' => 1,
                    'approved_by_owner_at' => now(), 'approved_by_council_at' => now(),
                ]);
            });
    }
}
