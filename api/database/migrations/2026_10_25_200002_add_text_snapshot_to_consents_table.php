<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MSG-02 / PRV-02 — the consent row keeps the full text the user accepted (not only its hash) and the language
 * it was shown in. Older rows keep `null` (hash + version only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consents', function (Blueprint $table): void {
            $table->longText('text_snapshot')->nullable()->after('text_hash');
            $table->string('locale', 5)->nullable()->after('text_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('consents', function (Blueprint $table): void {
            $table->dropColumn(['text_snapshot', 'locale']);
        });
    }
};
