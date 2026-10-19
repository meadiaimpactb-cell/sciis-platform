<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('interest_id')->nullable()->constrained('interests')->nullOnDelete();
            $table->string('title');
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->string('location')->nullable();
            $table->string('video_url')->nullable();
            $table->foreignId('organizer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('scheduled')->index(); // MeetingStatus
            $table->text('minutes')->nullable();
            $table->timestamp('ics_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meeting_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20); // investor|owner|council
            $table->boolean('attended')->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'user_id']);
        });

        Schema::create('meeting_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->text('description');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_actions');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};
