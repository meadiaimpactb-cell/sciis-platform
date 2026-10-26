<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OPP-07 / OPP-08 — saved browse filters gain a `kind` (`filter` = a named search, `profile` = the single
 * "any new opportunity matching my profile" switch), and `opportunity_alerts` records every alert sent so an
 * investor is told about a newly listed opportunity at most once, whichever filter matched it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_filters', function (Blueprint $table): void {
            $table->string('kind', 20)->default('filter')->after('user_id');
            $table->index(['user_id', 'kind']);
        });

        Schema::create('opportunity_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('saved_filter_id')->nullable()->constrained('saved_filters')->nullOnDelete();
            $table->string('source', 20); // filter | profile
            $table->timestamp('sent_at');
            $table->unique(['user_id', 'opportunity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_alerts');

        Schema::table('saved_filters', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
