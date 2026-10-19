<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\InterestStatus;
use App\Http\Requests\ApiRequest;

class TransitionInterestRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', $this->enumRule(InterestStatus::class)],
            'reason' => ['required_if:to,closed', 'nullable', 'string', 'max:2000'],
        ];
    }
}
