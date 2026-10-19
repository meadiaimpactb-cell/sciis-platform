<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Http\Requests\ApiRequest;

class InfoRequestRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:3', 'max:190'],
            'body' => ['required', 'string', 'min:10', 'max:4000'],
        ];
    }
}
