<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments — membership fees / paid services / training / sponsorship ONLY (SPEC §0, §6).
 * Never escrow, never investor money for deals, never securities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('vat_rate', 5, 4)->default(0.15);
            $table->decimal('vat_amount', 12, 2);
            $table->decimal('total', 12, 2);
            $table->timestamp('issued_at');
            $table->foreignId('pdf_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 30)->index(); // PaymentPurpose
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('SAR');
            $table->string('status', 20)->default('pending')->index(); // PaymentStatus
            $table->string('gateway', 30)->default('moyasar');
            $table->string('gateway_payment_id', 120)->nullable()->unique();
            $table->jsonb('gateway_payload')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('description')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('failed_reason')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('type', 60);
            $table->jsonb('payload')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('gateway_event_id', 120)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};
