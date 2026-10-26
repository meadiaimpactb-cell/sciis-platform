<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FRM-01 — answers to the admin-defined "additional questions" are stored per record as JSON together with the
 * schema version they were validated against: `{key, version, answers: {question_id: value}, answered_at}`.
 * `form_schemas.published_by` records who published each version (the row's created_at is the publish time).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['investor_profiles', 'opportunities', 'memberships'] as $table) {
            if (! Schema::hasColumn($table, 'extra_answers')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->json('extra_answers')->nullable();
                });
            }
        }

        if (! Schema::hasColumn('form_schemas', 'published_by')) {
            Schema::table('form_schemas', function (Blueprint $t): void {
                $t->unsignedBigInteger('published_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['investor_profiles', 'opportunities', 'memberships'] as $table) {
            if (Schema::hasColumn($table, 'extra_answers')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dropColumn('extra_answers');
                });
            }
        }

        if (Schema::hasColumn('form_schemas', 'published_by')) {
            Schema::table('form_schemas', function (Blueprint $t): void {
                $t->dropColumn('published_by');
            });
        }
    }
};
