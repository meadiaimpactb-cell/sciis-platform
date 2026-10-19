<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Http\Requests\ApiRequest;

class NdaAcceptRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['version' => ['required', 'string', 'max:20']];
    }
}
