<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** AGR (P2) — modelled now so the schema does not churn. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);
            $table->string('title');
            $table->longText('body'); // markdown with {{placeholders}}
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(false);
            $table->timestamp('legal_approved_at')->nullable();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        Schema::create('agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('interest_id')->nullable()->constrained('interests')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('agreement_templates')->nullOnDelete();
            $table->string('status', 20)->default('draft')->index(); // AgreementStatus
            $table->boolean('council_is_party')->default(false);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('signed_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agreement_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agreement_id')->constrained('agreements')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('body');
            $table->text('change_note')->nullable();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['agreement_id', 'version']);
        });

        Schema::table('agreements', function (Blueprint $table): void {
            $table->foreign('current_version_id')->references('id')->on('agreement_versions')->nullOnDelete();
        });

        Schema::create('agreement_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('agreement_versions')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 30); // approved|changes_requested
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_reviews');
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('agreements', fn (Blueprint $t) => $t->dropForeign(['current_version_id']));
        }
        Schema::dropIfExists('agreement_versions');
        Schema::dropIfExists('agreements');
        Schema::dropIfExists('agreement_templates');
    }
};
