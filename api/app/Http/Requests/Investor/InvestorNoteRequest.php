<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Http\Requests\ApiRequest;

/** OPP-09 — a private note on an opportunity (visible to its author only). */
class InvestorNoteRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:4000'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:5'],
            'tags.*' => ['string', 'max:40'],
        ];
    }
}
