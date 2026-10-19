<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

/** ADM-05 */
class InternalNoteRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'min:2', 'max:5000']];
    }
}
