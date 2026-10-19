<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

/** MTG-01 */
class MeetingRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $req = $isCreate ? 'required' : 'sometimes';

        return [
            'opportunity_uuid' => [$isCreate ? 'required' : 'prohibited', 'string', 'exists:opportunities,uuid'],
            'interest_id' => ['nullable', 'integer', 'exists:interests,id'],
            'title' => [$req, 'string', 'min:3', 'max:190'],
            'starts_at' => [$req, 'date', 'after:now'],
            'ends_at' => [$req, 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'video_url' => ['nullable', 'url', 'max:255'],
            'participant_user_ids' => [$isCreate ? 'required' : 'sometimes', 'array', 'min:1', 'max:20'],
            'participant_user_ids.*' => ['integer', 'exists:users,id'],
            'status' => ['sometimes', 'string', 'in:scheduled,held,cancelled'],
        ];
    }
}
