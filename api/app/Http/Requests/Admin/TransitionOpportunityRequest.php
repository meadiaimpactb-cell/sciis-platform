<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\OpportunityStatus;
use App\Http\Requests\ApiRequest;

/** ADM-01 */
class TransitionOpportunityRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', $this->enumRule(OpportunityStatus::class)],
            'reason' => ['required_if:to,not_eligible,closed', 'nullable', 'string', 'max:2000'],
        ];
    }
}
