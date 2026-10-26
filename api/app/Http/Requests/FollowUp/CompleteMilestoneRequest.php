<?php

declare(strict_types=1);

namespace App\Http\Requests\FollowUp;

use App\Http\Requests\ApiRequest;

/** PRJ-01 — a milestone is marked done with its evidence (DocumentService re-checks size / type from settings). */
class CompleteMilestoneRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
            'file' => ['required', 'file', 'max:'.((int) setting('documents.max_size_mb', 25) * 1024)],
        ];
    }
}
