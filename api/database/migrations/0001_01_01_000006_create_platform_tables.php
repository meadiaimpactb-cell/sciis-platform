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
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->jsonb('value')->nullable();
            $table->string('group', 60)->index();
            $table->string('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('taxonomy_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 60);
            $table->string('key', 80);
            $table->string('label_ar');
            $table->string('label_en')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->unique(['group', 'key']);
            $table->index(['group', 'is_active', 'sort']);
        });

        // AUD-01/02 — append-only audit trail (immutability enforced by trigger + model).
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['auditable_type', 'auditable_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_logs_immutable_fn() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs is append-only (AUD-02): % not allowed', TG_OP;
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs;
                CREATE TRIGGER audit_logs_immutable
                    BEFORE UPDATE OR DELETE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION audit_logs_immutable_fn();
            SQL);
        }

        Schema::create('form_schemas', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('schema');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        foreach (['pages', 'faqs', 'news_posts', 'events'] as $content) {
            Schema::create($content, function (Blueprint $table) use ($content): void {
                $table->id();
                $table->string('slug', 160)->unique();
                $table->string('title_ar');
                $table->string('title_en')->nullable();
                $table->longText('body_ar')->nullable();
                $table->longText('body_en')->nullable();
                $table->string('version', 20)->nullable(); // legal page versions (consents)
                $table->boolean('is_published')->default(false)->index();
                $table->timestamp('published_at')->nullable();
                $table->unsignedInteger('sort')->default(0);
                if ($content === 'events') {
                    $table->timestamp('starts_at')->nullable()->index();
                    $table->timestamp('ends_at')->nullable();
                    $table->string('location')->nullable();
                    $table->string('registration_url')->nullable();
                }
                if ($content === 'faqs') {
                    $table->string('category', 60)->nullable()->index();
                }
                $table->jsonb('meta')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::create('contact_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 30)->index(); // investor|entrepreneur|partnership|media|other
            $table->string('name');
            $table->string('email');
            $table->string('phone', 20)->nullable();
            $table->text('message');
            $table->string('reference_no', 30)->unique();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('new')->index(); // new|in_progress|closed
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        foreach (['events', 'news_posts', 'faqs', 'pages'] as $content) {
            Schema::dropIfExists($content);
        }
        Schema::dropIfExists('form_schemas');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs; DROP FUNCTION IF EXISTS audit_logs_immutable_fn();');
        }
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('taxonomy_terms');
        Schema::dropIfExists('settings');
    }
};
