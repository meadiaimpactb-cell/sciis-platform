<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payments — membership / service / training / sponsorship only (SPEC §0, §6).
 * The platform never collects investor money for deals, never holds escrow, never issues securities.
 */
class Payment extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id', 'purpose', 'amount', 'currency', 'status', 'gateway', 'gateway_payment_id', 'gateway_payload',
        'idempotency_key', 'description', 'invoice_id', 'paid_at', 'failed_reason', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'gateway_payload' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
