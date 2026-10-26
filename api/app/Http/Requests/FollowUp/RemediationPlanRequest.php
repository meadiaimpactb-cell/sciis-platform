<?php

declare(strict_types=1);

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\ApiRequest;

/** PRJ-03 — the owner's remediation plan for a delayed / stalled project. */
class RemediationPlanRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['remediation_plan' => ['required', 'string', 'min:10', 'max:5000']];
    }
}
