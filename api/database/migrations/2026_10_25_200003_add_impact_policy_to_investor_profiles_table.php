<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** INV-01 — institutional investors: impact / ESG policy (text; the optional file is an `impact_policy` document). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_profiles', function (Blueprint $table): void {
            $table->text('impact_policy')->nullable()->after('mentoring_areas');
        });
    }

    public function down(): void
    {
        Schema::table('investor_profiles', function (Blueprint $table): void {
            $table->dropColumn('impact_policy');
        });
    }
};
