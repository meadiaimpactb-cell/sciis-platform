<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Http\Requests\ApiRequest;

/** ACC-08 — grant / withdraw "can act", disable / re-enable a colleague. */
class UpdateMemberRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'can_act' => ['sometimes', 'boolean'],
            'disabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            if (! $this->has('can_act') && ! $this->has('disabled')) {
                $v->errors()->add('can_act', __('messages.organization.nothing_to_update'));
            }
        });
    }
}
