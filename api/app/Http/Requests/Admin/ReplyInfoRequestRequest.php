<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

class ReplyInfoRequestRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['answer' => ['required', 'string', 'min:5', 'max:5000']];
    }
}
