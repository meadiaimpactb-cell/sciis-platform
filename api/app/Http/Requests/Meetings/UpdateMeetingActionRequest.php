<?php

declare(strict_types=1);

namespace App\Http\Requests\Meetings;

use App\Http\Requests\ApiRequest;

/** MTG-02 — mark a meeting follow-up action done (true) or reopen it (false). */
class UpdateMeetingActionRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'done' => ['required', 'boolean'],
        ];
    }
}
