<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

/** MAT-05 */
class TeaserRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'summary_ar' => ['required', 'string', 'min:20', 'max:600'],
            'summary_en' => ['nullable', 'string', 'max:600'],
            'highlights' => ['nullable', 'array', 'max:6'],
            'highlights.*' => ['string', 'max:160'],
        ];
    }
}
