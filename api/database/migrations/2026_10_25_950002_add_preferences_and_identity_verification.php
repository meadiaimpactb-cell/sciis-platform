<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UX-05 / BRD-04 — `users.preferences` (calendar: gregorian|hijri|both, theme: system|light|dark).
 * ACC-09 — national identity verification (Nafath): the verified timestamp + a keyed hash of the national ID on the
 * user (the number itself is never stored), and one row per verification request (start → poll → result).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            if (! Schema::hasColumn('users', 'preferences')) {
                $t->json('preferences')->nullable();
            }
            if (! Schema::hasColumn('users', 'national_id_verified_at')) {
                $t->timestamp('national_id_verified_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'national_id_hash')) {
                $t->string('national_id_hash', 64)->nullable()->index();
            }
        });

        if (! Schema::hasTable('identity_verifications')) {
            Schema::create('identity_verifications', function (Blueprint $t): void {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('driver', 30);
                $t->string('national_id_hash', 64);
                // Encrypted national ID, kept only while the request is pending (the status call needs it), then wiped.
                $t->text('national_id_encrypted')->nullable();
                $t->string('transaction_id', 120)->nullable();
                $t->string('random', 10)->nullable(); // the number the user picks in the Nafath app
                $t->string('status', 20)->default('pending'); // pending|approved|rejected|expired|failed
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');

        Schema::table('users', function (Blueprint $t): void {
            foreach (['preferences', 'national_id_verified_at', 'national_id_hash'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    if ($column === 'national_id_hash') {
                        $t->dropIndex(['national_id_hash']);
                    }
                    $t->dropColumn($column);
                }
            }
        });
    }
};
