<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MTG-04 — optional on-platform event registration (capacity, deadline, manual close) next to the external
 * `registration_url`, and the registrants with their attendance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->boolean('registration_enabled')->default(false);
            $table->unsignedInteger('registration_capacity')->nullable();
            $table->timestamp('registration_deadline')->nullable();
            $table->timestamp('registration_closed_at')->nullable();
        });

        Schema::create('event_registrations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 20)->nullable();
            $table->string('organization', 190)->nullable();
            $table->string('status', 20)->default('registered')->index(); // registered | cancelled
            $table->string('locale', 2)->default('ar');
            $table->timestamp('attended_at')->nullable();
            $table->foreignId('attendance_marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'email']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['registration_enabled', 'registration_capacity', 'registration_deadline', 'registration_closed_at']);
        });
    }
};
