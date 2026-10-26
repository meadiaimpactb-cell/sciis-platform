<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Http\Requests\ApiRequest;

/** ACC-08 — the representative invites a colleague by e-mail. */
class InviteMemberRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['nullable', 'string', 'min:3', 'max:120'],
            'can_act' => ['sometimes', 'boolean'],
        ];
    }
}
