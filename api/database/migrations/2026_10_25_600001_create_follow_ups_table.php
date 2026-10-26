<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRJ-01..03 — one follow-up record per project after connection: milestone-plan approval state (PRJ-01),
 * computed / overridden execution status with reason, remediation plan and escalation (PRJ-03),
 * reporting anchor + reminder log (PRJ-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->unique()->constrained('opportunities')->cascadeOnDelete();
            // PRJ-01 plan: draft | submitted | approved | changes_requested
            $table->string('plan_status', 20)->default('draft');
            $table->unsignedInteger('plan_version')->default(0);
            $table->timestamp('plan_submitted_at')->nullable();
            $table->timestamp('plan_reviewed_at')->nullable();
            $table->foreignId('plan_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('plan_review_note')->nullable();
            // PRJ-03 execution status (ExecutionStatus): computed nightly / after each change, optional staff override.
            $table->string('computed_status', 20)->nullable();
            $table->timestamp('status_computed_at')->nullable();
            $table->string('status_override', 20)->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('override_at')->nullable();
            $table->text('remediation_plan')->nullable();
            $table->timestamp('remediation_updated_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            // PRJ-02 reporting: periods are counted from the start of execution; null frequency = settings default.
            $table->timestamp('execution_started_at')->nullable();
            $table->string('report_frequency', 20)->nullable();
            $table->json('reminders_sent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
