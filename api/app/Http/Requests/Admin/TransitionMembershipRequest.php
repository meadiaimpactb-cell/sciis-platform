<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\MembershipStatus;
use App\Http\Requests\ApiRequest;

/** ADM-06 */
class TransitionMembershipRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', $this->enumRule(MembershipStatus::class)],
            'note' => ['required_if:to,rejected,suspended,needs_completion', 'nullable', 'string', 'max:2000'],
        ];
    }
}
