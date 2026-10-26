<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OPP-11 — optional English versions of the public card fields (the teaser already has `summary_en`).
 * Served to investors when the request locale is `en`, falling back to the Arabic text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->string('name_en', 190)->nullable()->after('name');
            $table->string('short_description_en', 300)->nullable()->after('short_description');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropColumn(['name_en', 'short_description_en']);
        });
    }
};
