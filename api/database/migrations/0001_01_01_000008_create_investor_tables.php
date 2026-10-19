<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('country', 80)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('employer')->nullable();
            $table->string('job_title')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->unsignedTinyInteger('experience_years')->nullable();
            $table->text('experience_desc')->nullable();
            $table->unsignedSmallInteger('prior_investments_count')->nullable();
            $table->text('prior_investments_desc')->nullable();
            $table->boolean('has_social_investments')->nullable();
            $table->text('social_investments_desc')->nullable();
            $table->string('risk_appetite', 10)->nullable(); // RiskAppetite
            $table->boolean('mentoring_available')->nullable();
            $table->text('mentoring_areas')->nullable();
            // doc §7.5: kept but not shown — status pending legal.
            $table->jsonb('accredited_declaration')->nullable();
            $table->foreignId('investment_range_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete(); // confidential
            $table->foreignId('annual_investment_band_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete(); // confidential
            $table->string('preferred_contact', 20)->nullable();
            $table->foreignId('how_heard_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete();
            $table->unsignedBigInteger('cv_document_id')->nullable();
            $table->text('conflicts_disclosure')->nullable();
            $table->boolean('commit_meetings')->nullable();
            $table->unsignedTinyInteger('completion_percent')->default(0);
            $table->timestamp('reminder_last_sent_at')->nullable();
            $table->timestamps();
        });

        foreach ([
            'investor_profile_sectors' => 'sector_id',
            'investor_profile_stages' => 'stage_id',
            'investor_profile_investment_types' => 'investment_type_id',
            'investor_profile_regions' => 'region_id',
            'investor_profile_causes' => 'cause_id',
        ] as $pivot => $column) {
            Schema::create($pivot, function (Blueprint $table) use ($column): void {
                $table->foreignId('investor_profile_id')->constrained('investor_profiles')->cascadeOnDelete();
                $table->foreignId($column)->constrained('taxonomy_terms')->cascadeOnDelete();
                $table->primary(['investor_profile_id', $column]);
            });
        }

        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('registered')->index(); // MembershipStatus
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable(); // internal
            $table->timestamp('terms_accepted_at')->nullable();
            $table->text('suspended_reason')->nullable();
            $table->text('completion_note')->nullable(); // shown to applicant when needs_completion
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
        foreach (['investor_profile_causes', 'investor_profile_regions', 'investor_profile_investment_types', 'investor_profile_stages', 'investor_profile_sectors'] as $pivot) {
            Schema::dropIfExists($pivot);
        }
        Schema::dropIfExists('investor_profiles');
    }
};
