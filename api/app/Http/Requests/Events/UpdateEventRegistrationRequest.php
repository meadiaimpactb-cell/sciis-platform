<?php

declare(strict_types=1);

namespace App\Http\Requests\Events;

use App\Http\Requests\ApiRequest;
use App\Models\EventRegistration;

/** MTG-04 — attendance marking / cancellation of one registration. */
class UpdateEventRegistrationRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attended' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'in:'.EventRegistration::STATUS_REGISTERED.','.EventRegistration::STATUS_CANCELLED],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            if (! $this->has('attended') && ! $this->has('status')) {
                $v->errors()->add('attended', __('messages.events.nothing_to_update'));
            }
        });
    }
}
