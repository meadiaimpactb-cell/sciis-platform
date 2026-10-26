<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Http\Requests\ApiRequest;
use App\Support\OpportunityFilters;

/**
 * OPP-08 — save / rename a catalogue search. `filters` uses the browse keys (OpportunityFilters: sector, region,
 * stage, type, range, beneficiary, sdg, search, nominated, sort); unknown keys are dropped. `notify` = OPP-07 alerts.
 */
class SavedFilterRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'filters' => [$creating ? 'required' : 'prohibited', 'array'],
            'filters.*' => ['nullable', 'max:120'],
            'notify' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string|bool> normalized filters (create only) */
    public function filters(): array
    {
        return OpportunityFilters::normalize((array) $this->validated('filters', []));
    }
}
