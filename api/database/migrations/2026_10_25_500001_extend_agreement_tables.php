<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AGR-01..05 — the agreement workflow on top of the P2 schema (0001_01_01_000014):
 *  - templates: English title/body (the existing `title` / `body` are the Arabic text), description, change note, author;
 *  - agreements: public uuid, title, investor party, workflow timestamps, signature method/provider, cancellation;
 *  - versions: English body, the filled placeholder values and the SHA-256 of the exact text (AGR-05);
 *  - agreement_signatures: one immutable evidence row per party and version (simple electronic signature, AGR-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreement_templates', function (Blueprint $table): void {
            $table->string('title_en')->nullable()->after('title');
            $table->longText('body_en')->nullable()->after('body');
            $table->text('description')->nullable()->after('body_en');
            $table->text('change_note')->nullable()->after('description');
            $table->foreignId('created_by')->nullable()->after('legal_approved_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('agreements', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('title')->nullable()->after('template_id');
            $table->foreignId('investor_user_id')->nullable()->after('interest_id')->constrained('users')->nullOnDelete();
            $table->string('signature_method', 20)->nullable()->after('signed_document_id'); // electronic | wet
            $table->string('signature_provider', 40)->nullable()->after('signature_method');
            $table->foreignId('created_by')->nullable()->after('signature_provider')->constrained('users')->nullOnDelete();
            $table->timestamp('review_requested_at')->nullable()->after('created_by');
            $table->timestamp('approved_at')->nullable()->after('review_requested_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('sent_for_signature_at')->nullable()->after('approved_by');
            $table->timestamp('signed_at')->nullable()->after('sent_for_signature_at');
            $table->timestamp('cancelled_at')->nullable()->after('archived_at');
            $table->text('cancel_reason')->nullable()->after('cancelled_at');
        });

        foreach (DB::table('agreements')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('agreements')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('agreements', function (Blueprint $table): void {
            $table->unique('uuid');
        });

        Schema::table('agreement_versions', function (Blueprint $table): void {
            $table->longText('body_en')->nullable()->after('body');
            $table->json('placeholders')->nullable()->after('body_en');
            $table->string('text_hash', 64)->nullable()->after('placeholders');
        });

        Schema::create('agreement_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agreement_id')->constrained('agreements')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('agreement_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('party', 20); // owner | investor
            $table->string('signer_name');
            $table->string('signer_email')->nullable();
            $table->string('provider', 40); // SignatureProvider key (simple, …)
            $table->string('provider_reference')->nullable();
            $table->string('text_hash', 64);
            $table->text('consent_text');
            $table->string('locale', 5)->default('ar');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->timestamp('signed_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['version_id', 'party']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_signatures');

        Schema::table('agreement_versions', function (Blueprint $table): void {
            $table->dropColumn(['body_en', 'placeholders', 'text_hash']);
        });

        Schema::table('agreements', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropConstrainedForeignId('investor_user_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'uuid', 'title', 'signature_method', 'signature_provider', 'review_requested_at', 'approved_at',
                'sent_for_signature_at', 'signed_at', 'cancelled_at', 'cancel_reason',
            ]);
        });

        Schema::table('agreement_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['title_en', 'body_en', 'description', 'change_note']);
        });
    }
};
