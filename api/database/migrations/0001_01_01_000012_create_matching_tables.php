<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'opportunity_id']);
        });

        Schema::create('nominations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('investor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('nominated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('matched_criteria')->nullable(); // MAT-02 transparent rule
            $table->unsignedTinyInteger('match_score')->default(0);
            $table->text('rationale')->nullable(); // MAT-03
            $table->unsignedInteger('teaser_version')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('response', 20)->nullable(); // interested|declined
            $table->foreignId('decline_reason_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete();
            $table->text('decline_note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['opportunity_id', 'investor_user_id']);
        });

        Schema::create('interests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('investor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('nomination_id')->nullable()->constrained('nominations')->nullOnDelete();
            $table->string('status', 30)->default('sent')->index(); // InterestStatus
            $table->string('owner_decision', 20)->nullable(); // approved|declined
            $table->timestamp('owner_decided_at')->nullable();
            $table->timestamp('owner_decision_due_at')->nullable();
            $table->foreignId('decline_reason_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete();
            $table->text('note')->nullable();
            $table->text('closed_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['opportunity_id', 'investor_user_id']);
        });

        Schema::create('info_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('investor_user_id')->constrained('users')->cascadeOnDelete(); // never forwarded (OPP-05, MSG-01)
            $table->string('subject');
            $table->text('body');
            $table->string('status', 20)->default('sent')->index(); // InfoRequestStatus
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('forwarded_to_owner_at')->nullable();
            $table->text('owner_answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
        });

        // P2
        Schema::create('saved_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->jsonb('filters');
            $table->boolean('notify')->default(false);
            $table->timestamps();
        });

        // P2 (OPP-09) — private notes.
        Schema::create('investor_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->text('body');
            $table->jsonb('tags')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'opportunity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_notes');
        Schema::dropIfExists('saved_filters');
        Schema::dropIfExists('info_requests');
        Schema::dropIfExists('interests');
        Schema::dropIfExists('nominations');
        Schema::dropIfExists('saved_opportunities');
    }
};
