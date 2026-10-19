<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_no', 20)->nullable()->unique(); // A-YYYY-NNNN generated on submit
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name');
            $table->string('short_description', 280)->nullable();
            $table->foreignId('sector_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete();
            $table->string('stage', 20)->nullable(); // ProjectStage
            $table->string('status', 30)->default('draft')->index(); // OpportunityStatus
            $table->boolean('is_listed')->default(true);
            $table->string('website')->nullable();
            $table->jsonb('social_links')->nullable();
            $table->text('problem_statement')->nullable();
            $table->text('solution')->nullable();
            $table->text('expected_change')->nullable();
            $table->text('beneficiaries_desc')->nullable();
            $table->unsignedInteger('beneficiaries_current')->nullable();
            $table->unsignedInteger('beneficiaries_target')->nullable();
            $table->string('revenue_model_type', 60)->nullable();
            $table->text('revenue_model_desc')->nullable();
            $table->string('revenue_state', 20)->nullable(); // RevenueState
            $table->foreignId('last_year_revenue_band_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete();
            $table->decimal('funding_amount', 14, 2)->nullable(); // confidential (SAR)
            $table->string('funding_amount_display', 10)->nullable(); // exact|band|hidden (null = settings default)
            $table->foreignId('funding_range_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete(); // band used by matching
            $table->jsonb('use_of_funds')->nullable(); // confidential [{item, percent}]
            $table->boolean('prior_funding')->nullable();
            $table->text('prior_funding_desc')->nullable(); // confidential
            $table->boolean('legal_disputes')->nullable();
            $table->text('legal_disputes_desc')->nullable(); // confidential
            $table->boolean('license_required')->nullable();
            $table->string('license_status', 40)->nullable();
            $table->text('operational_risks')->nullable(); // confidential
            $table->boolean('council_relationship')->nullable();
            $table->text('council_relationship_desc')->nullable(); // internal
            $table->timestamp('data_accuracy_declared_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_due_at')->nullable();
            $table->timestamp('completion_due_at')->nullable();
            $table->timestamp('evaluation_due_at')->nullable();
            $table->string('evaluation_result', 30)->nullable(); // EvaluationResult
            $table->timestamp('evaluation_approved_at')->nullable();
            $table->string('execution_status', 20)->nullable(); // ExecutionStatus
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'is_listed']);
            $table->index(['owner_user_id', 'status']);
            $table->index('assigned_reviewer_id');
        });

        foreach ([
            'opportunity_regions' => 'region_id',
            'opportunity_investment_types' => 'investment_type_id',
            'opportunity_beneficiary_groups' => 'beneficiary_group_id',
            'opportunity_sdgs' => 'sdg_id',
        ] as $pivot => $column) {
            Schema::create($pivot, function (Blueprint $table) use ($column): void {
                $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
                $table->foreignId($column)->constrained('taxonomy_terms')->cascadeOnDelete();
                $table->primary(['opportunity_id', $column]);
            });
        }

        // Append-only transition log (ADM-01).
        Schema::create('opportunity_status_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['opportunity_id', 'created_at']);
        });

        Schema::create('teasers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->unique()->constrained('opportunities')->cascadeOnDelete();
            $table->text('summary_ar');
            $table->text('summary_en')->nullable();
            $table->jsonb('highlights')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('approved_by_owner_at')->nullable();
            $table->timestamp('approved_by_council_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('impact_indicators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->string('name');
            $table->text('definition')->nullable();
            $table->string('unit', 60)->nullable();
            $table->decimal('baseline', 16, 2)->nullable();
            $table->decimal('target', 16, 2)->nullable();
            $table->string('data_source')->nullable();
            $table->string('frequency', 20)->nullable(); // monthly|quarterly|semiannual|annual
            $table->string('verification_method')->nullable();
            $table->string('iris_code', 40)->nullable(); // P3
            $table->foreignId('sdg_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_disclosed')->default(false);
            $table->timestamps();
            $table->index(['opportunity_id', 'is_primary']);
        });

        Schema::create('indicator_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('impact_indicator_id')->constrained('impact_indicators')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('value', 16, 2);
            $table->unsignedBigInteger('evidence_document_id')->nullable();
            $table->string('status', 30)->default('entered'); // IndicatorValueStatus
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // ADM-04 / ENT-04 — "المطلوب منك الآن".
        Schema::create('completion_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->jsonb('items'); // [{field|document_kind, note}]
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['opportunity_id', 'resolved_at']);
        });

        // ADM-05 — never exposed to external users.
        Schema::create('internal_notes', function (Blueprint $table): void {
            $table->id();
            $table->morphs('notable');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_notes');
        Schema::dropIfExists('completion_requests');
        Schema::dropIfExists('indicator_values');
        Schema::dropIfExists('impact_indicators');
        Schema::dropIfExists('teasers');
        Schema::dropIfExists('opportunity_status_transitions');
        foreach (['opportunity_sdgs', 'opportunity_beneficiary_groups', 'opportunity_investment_types', 'opportunity_regions'] as $pivot) {
            Schema::dropIfExists($pivot);
        }
        Schema::dropIfExists('opportunities');
    }
};
