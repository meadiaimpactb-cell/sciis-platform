<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PRJ / IMP (P2) — modelled now. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->string('title');
            $table->text('deliverable')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 20)->default('planned'); // planned|in_progress|done|delayed
            $table->timestamps();
        });

        Schema::create('progress_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->text('achieved')->nullable();
            $table->text('delayed')->nullable();
            $table->text('risks')->nullable();
            $table->text('support_needed')->nullable();
            $table->string('execution_status', 20)->nullable(); // ExecutionStatus
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // ENT-10 (P2)
        Schema::create('investor_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->jsonb('metrics')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_updates');
        Schema::dropIfExists('progress_reports');
        Schema::dropIfExists('milestones');
    }
};
