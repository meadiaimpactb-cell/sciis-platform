<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use App\Models\NotificationPreference;

class NotificationPreferencesRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array'],
            'preferences.*.category' => ['required', 'string', 'in:'.implode(',', NotificationPreference::CATEGORIES)],
            'preferences.*.mail' => ['sometimes', 'boolean'],
            'preferences.*.sms' => ['sometimes', 'boolean'],
        ];
    }
}
