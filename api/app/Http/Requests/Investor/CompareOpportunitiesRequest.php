<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Http\Requests\ApiRequest;

/** OPP-12 — `ids` = 2 to 4 opportunity uuids (array or comma list). */
class CompareOpportunitiesRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $ids = $this->input('ids');
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        $this->merge(['ids' => is_array($ids) ? array_values(array_unique(array_filter(array_map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $ids)))) : $ids]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:2', 'max:4'],
            'ids.*' => ['string', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ids.required' => __('messages.discovery.compare_range'),
            'ids.min' => __('messages.discovery.compare_range'),
            'ids.max' => __('messages.discovery.compare_range'),
        ];
    }
}
