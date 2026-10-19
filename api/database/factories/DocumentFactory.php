<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\ScanResult;
use App\Enums\Sensitivity;
use App\Models\Document;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory()->entrepreneur(),
            'documentable_type' => 'opportunity',
            'documentable_id' => Opportunity::factory(),
            'kind' => DocumentKind::PitchDeck,
            'title' => 'مستند (افتراضي)',
            'disk' => 'local',
            'path' => 'opportunity/test/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', fake()->uuid()),
            'sensitivity' => Sensitivity::Confidential,
            'status' => DocumentStatus::Accepted,
            'version' => 1,
            'scan_result' => ScanResult::Clean,
            'scanned_at' => now(),
        ];
    }

    public function forOpportunity(Opportunity $opportunity): static
    {
        return $this->state(fn (): array => [
            'documentable_type' => 'opportunity',
            'documentable_id' => $opportunity->id,
            'owner_user_id' => $opportunity->owner_user_id,
        ]);
    }
}
