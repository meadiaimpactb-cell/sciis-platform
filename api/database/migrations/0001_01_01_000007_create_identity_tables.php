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
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('legal_form', 30)->nullable(); // LegalForm
            $table->string('institution_type', 30)->nullable(); // InstitutionType (investors)
            $table->string('cr_number', 30)->nullable();
            $table->string('unified_number', 30)->nullable();
            $table->string('license_number', 60)->nullable();
            $table->date('founded_on')->nullable();
            $table->unsignedInteger('employees_count')->nullable();
            $table->string('website')->nullable();
            $table->foreignId('region_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete();
            $table->string('city', 100)->nullable();
            $table->foreignId('representative_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('authorization_letter_document_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::create('otp_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 10); // email|sms
            $table->string('purpose', 30)->default('verify'); // verify|login|reset
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'channel', 'purpose']);
        });

        // PRV-01/02 — consents are insert-only (no updates ever).
        Schema::create('consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40)->index(); // ConsentType
            $table->string('version', 20);
            $table->string('text_hash', 64);
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->nullableMorphs('subject');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'type']);
        });

        Schema::create('data_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20); // access|rectify|erase (PRV-03)
            $table->text('note')->nullable();
            $table->string('status', 20)->default('open'); // open|in_progress|done|rejected
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
        Schema::dropIfExists('consents');
        Schema::dropIfExists('otp_codes');
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign(['organization_id']);
            });
        }
        Schema::dropIfExists('organizations');
    }
};
