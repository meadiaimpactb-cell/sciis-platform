<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-01 / DOC-01 — application-level encryption at rest for files kept on a non-S3 disk.
 * Existing rows stay `false` (plaintext keeps working); `php artisan documents:encrypt-existing` converts them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->boolean('encrypted')->default(false)->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('encrypted');
        });
    }
};
