<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payment */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() ?? false;

        return [
            'uuid' => $this->uuid,
            'purpose' => $this->purpose->value,
            'purpose_label' => $this->purpose->label(),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'gateway' => $this->gateway,
            'gateway_payment_id' => $this->gateway_payment_id,
            'description' => $this->description,
            'invoice' => $this->invoice ? ['number' => $this->invoice->number, 'total' => (float) $this->invoice->total, 'vat_amount' => (float) $this->invoice->vat_amount, 'issued_at' => $this->invoice->issued_at?->toIso8601String()] : null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'failed_reason' => $this->failed_reason,
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'user' => $this->when($isStaff, fn () => ['id' => $this->user?->id, 'name' => $this->user?->name, 'email' => $this->user?->email]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
