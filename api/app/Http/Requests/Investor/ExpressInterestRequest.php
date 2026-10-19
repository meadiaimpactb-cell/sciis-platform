<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Http\Requests\ApiRequest;

class ExpressInterestRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:2000']];
    }
}
