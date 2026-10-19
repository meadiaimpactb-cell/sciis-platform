<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Enums\PaymentPurpose;
use App\Http\Requests\ApiRequest;

class CreateIntentRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', $this->enumRule(PaymentPurpose::class)],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'description' => ['nullable', 'string', 'max:190'],
        ];
    }
}
