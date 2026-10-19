<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

/** PRV-03 */
class DataRequestRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:access,rectify,erase'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
