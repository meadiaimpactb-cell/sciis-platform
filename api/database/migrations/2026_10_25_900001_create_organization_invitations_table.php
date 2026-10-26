<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-08 — multi-user institutional accounts: e-mailed invitations (only the token hash is stored) and, per member,
 * the "can act" grant and the representative's disable switch (kept apart from a staff suspension).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('name', 120)->nullable();
            $table->boolean('can_act')->default(false);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('organization_can_act')->default(false);
            $table->timestamp('organization_disabled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['organization_can_act', 'organization_disabled_at']);
        });
        Schema::dropIfExists('organization_invitations');
    }
};
