<?php

declare(strict_types=1);

namespace App\Http\Requests\FollowUp;

use App\Enums\ExecutionStatus;
use App\Http\Requests\ApiRequest;

/** PRJ-03 — staff override of the computed execution status (reason required); `status: null` returns to the computed one. */
class ExecutionStatusRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['present', 'nullable', 'string', $this->enumRule(ExecutionStatus::class)],
            'reason' => ['required_with:status', 'nullable', 'string', 'min:5', 'max:2000'],
        ];
    }
}
