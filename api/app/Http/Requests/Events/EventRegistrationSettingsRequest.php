<?php

declare(strict_types=1);

namespace App\Http\Requests\Events;

use App\Http\Requests\ApiRequest;

/** MTG-04 — staff switch the on-platform registration of an event on/off, with capacity, deadline and manual close. */
class EventRegistrationSettingsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'closed' => ['sometimes', 'boolean'],
        ];
    }
}
