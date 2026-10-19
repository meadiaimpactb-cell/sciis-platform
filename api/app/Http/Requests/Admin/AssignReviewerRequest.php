<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

class AssignReviewerRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reviewer_user_id' => ['required', 'integer', 'exists:users,id']];
    }
}
