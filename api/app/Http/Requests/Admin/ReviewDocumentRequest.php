<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\DocumentStatus;
use App\Http\Requests\ApiRequest;

class ReviewDocumentRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', $this->enumRule(DocumentStatus::class)],
            'note' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ];
    }
}
