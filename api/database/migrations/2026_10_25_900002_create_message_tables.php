<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MTG-03 — in-platform messages tied to a record: one thread per subject (opportunity file, interest or nomination),
 * messages between council staff and the record's party, and a per-user read marker for unread counts.
 * Attachments are `documents` rows (documentable = thread_message) through the secure document pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);
        });

        Schema::create('thread_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_side', 10); // staff | party
            $table->text('body')->nullable();
            $table->timestamps();
            $table->index(['thread_id', 'created_at']);
        });

        Schema::create('message_thread_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0); // unread = later messages of the other side
            $table->timestamp('last_read_at');
            $table->unique(['thread_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_thread_reads');
        Schema::dropIfExists('thread_messages');
        Schema::dropIfExists('message_threads');
    }
};
