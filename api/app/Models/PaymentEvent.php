<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Webhook log. */
class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['payment_id', 'type', 'payload', 'signature_valid', 'gateway_event_id', 'created_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_valid' => 'boolean', 'created_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
