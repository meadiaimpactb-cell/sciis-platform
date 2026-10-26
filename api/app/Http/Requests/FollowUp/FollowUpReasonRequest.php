<?php

declare(strict_types=1);

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\ApiRequest;

/** Staff action that needs a reason the owner reads (PRJ-01: reopen a completed milestone). */
class FollowUpReasonRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:2000']];
    }
}
