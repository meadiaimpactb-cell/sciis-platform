<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Http\Requests\ApiRequest;

/** MSG-02 — `version` is the NDA version the investor was shown (GET …/nda); `locale` the language of that text. */
class NdaAcceptRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:20'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
        ];
    }
}
