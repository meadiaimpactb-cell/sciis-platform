<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

/** MTG-02 */
class MeetingMinutesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'minutes' => ['required', 'string', 'min:5', 'max:10000'],
            'attended_user_ids' => ['nullable', 'array'],
            'attended_user_ids.*' => ['integer', 'exists:users,id'],
            'actions' => ['nullable', 'array', 'max:30'],
            'actions.*.description' => ['required', 'string', 'max:500'],
            'actions.*.owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'actions.*.due_at' => ['nullable', 'date'],
        ];
    }
}
