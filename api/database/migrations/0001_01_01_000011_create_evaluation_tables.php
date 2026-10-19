<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_criteria', function (Blueprint $table): void {
            $table->id();
            $table->string('axis', 20); // EvaluationAxis
            $table->string('key', 60);
            $table->string('label_ar');
            $table->string('label_en')->nullable();
            $table->decimal('weight', 5, 2)->default(1);
            $table->jsonb('scale_descriptions'); // {1:..,5:..}
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['key', 'version']);
            $table->index(['axis', 'is_active']);
        });

        Schema::create('evaluation_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('evaluator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('assigned'); // AssignmentStatus
            $table->timestamp('coi_declared_at')->nullable();
            $table->boolean('coi_has_conflict')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->unique(['opportunity_id', 'evaluator_user_id']);
        });

        Schema::create('evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->unique()->constrained('evaluation_assignments')->cascadeOnDelete();
            $table->unsignedInteger('criteria_version');
            $table->jsonb('criteria_snapshot')->nullable(); // EVL-01 snapshot
            $table->jsonb('scores'); // {criterion_key: {score, note}}
            $table->jsonb('axis_totals')->nullable();
            $table->decimal('overall', 6, 2)->nullable();
            $table->string('recommendation', 30); // EvaluationResult
            $table->text('comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('advisory_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('advisory_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('question');
            $table->timestamp('expires_at')->nullable();
            $table->text('opinion')->nullable();
            $table->timestamp('opined_at')->nullable();
            $table->timestamps();
        });

        Schema::create('evaluation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->string('result', 30); // EvaluationResult
            $table->text('conditions')->nullable();
            $table->text('reasons')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->foreignId('advisory_referral_id')->nullable()->constrained('advisory_referrals')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_decisions');
        Schema::dropIfExists('advisory_referrals');
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('evaluation_assignments');
        Schema::dropIfExists('evaluation_criteria');
    }
};
