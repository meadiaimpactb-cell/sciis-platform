<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRJ-01 milestone details + completion evidence, PRJ-02 report review workflow, IMP-02 verification reason,
 * ENT-10 owner updates (title, optional council review before sending).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('responsible', 190)->nullable();
            $table->decimal('budget_share', 5, 2)->nullable(); // % of the funding
            $table->unsignedSmallInteger('sort')->default(0);
            $table->text('evidence_note')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['opportunity_id', 'sort']);
        });

        Schema::table('progress_reports', function (Blueprint $table): void {
            $table->string('status', 20)->default('submitted'); // submitted | accepted | changes_requested
            $table->text('narrative')->nullable();
            $table->decimal('spend_amount', 14, 2)->nullable();
            $table->json('milestone_updates')->nullable(); // [{milestone_id, status, note}]
            $table->text('review_note')->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['opportunity_id', 'period_start']);
        });

        Schema::table('indicator_values', function (Blueprint $table): void {
            $table->text('review_note')->nullable();
            $table->index(['impact_indicator_id', 'status']);
        });

        Schema::table('investor_updates', function (Blueprint $table): void {
            $table->string('title', 190)->nullable();
            $table->string('status', 20)->default('published'); // pending_review | published | rejected
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['opportunity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('investor_updates', function (Blueprint $table): void {
            $table->dropIndex(['opportunity_id', 'status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['title', 'status', 'review_note']);
        });

        Schema::table('indicator_values', function (Blueprint $table): void {
            $table->dropIndex(['impact_indicator_id', 'status']);
            $table->dropColumn('review_note');
        });

        Schema::table('progress_reports', function (Blueprint $table): void {
            $table->dropIndex(['opportunity_id', 'period_start']);
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['status', 'narrative', 'spend_amount', 'milestone_updates', 'review_note', 'due_on']);
        });

        Schema::table('milestones', function (Blueprint $table): void {
            $table->dropIndex(['opportunity_id', 'sort']);
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['description', 'responsible', 'budget_share', 'sort', 'evidence_note']);
        });
    }
};
