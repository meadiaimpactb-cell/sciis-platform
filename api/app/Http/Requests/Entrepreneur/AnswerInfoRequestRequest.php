<?php

declare(strict_types=1);

namespace App\Http\Requests\Entrepreneur;

use App\Http\Requests\ApiRequest;

class AnswerInfoRequestRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['answer' => ['required', 'string', 'min:5', 'max:5000']];
    }
}
