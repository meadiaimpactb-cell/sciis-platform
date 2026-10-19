<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('documentable');
            $table->string('kind', 40)->index(); // DocumentKind
            $table->string('title')->nullable();
            $table->string('disk', 30);
            $table->string('path', 512);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('sensitivity', 30)->default('confidential'); // Sensitivity
            $table->string('status', 30)->default('pending_review')->index(); // DocumentStatus
            $table->text('reviewer_note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_result', 20)->default('pending'); // pending|clean|infected
            $table->timestamps();
            $table->softDeletes();
        });

        // Late FKs to documents.
        Schema::table('organizations', function (Blueprint $table): void {
            $table->foreign('authorization_letter_document_id')->references('id')->on('documents')->nullOnDelete();
        });
        Schema::table('investor_profiles', function (Blueprint $table): void {
            $table->foreign('cv_document_id')->references('id')->on('documents')->nullOnDelete();
        });
        Schema::table('indicator_values', function (Blueprint $table): void {
            $table->foreign('evidence_document_id')->references('id')->on('documents')->nullOnDelete();
        });

        // INV-04 / AUD — every view/download is logged.
        Schema::create('document_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20); // view|download
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['document_id', 'created_at']);
        });

        // ENT-06 — full-file access requires an active grant (owner approval + NDA consent).
        Schema::create('file_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('investor_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('granted_by_owner_at')->nullable();
            $table->foreignId('nda_consent_id')->nullable()->constrained('consents')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['opportunity_id', 'investor_user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_access_grants');
        Schema::dropIfExists('document_access_logs');
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('indicator_values', fn (Blueprint $t) => $t->dropForeign(['evidence_document_id']));
            Schema::table('investor_profiles', fn (Blueprint $t) => $t->dropForeign(['cv_document_id']));
            Schema::table('organizations', fn (Blueprint $t) => $t->dropForeign(['authorization_letter_document_id']));
        }
        Schema::dropIfExists('documents');
    }
};
