<?php

declare(strict_types=1);

namespace App\Http\Requests\Entrepreneur;

use App\Enums\DocumentKind;
use App\Http\Requests\ApiRequest;

/** DOC-01 — size and mime limits are enforced again in DocumentService from settings. */
class UploadDocumentRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) setting('documents.max_size_mb', 25) * 1024;

        return [
            'kind' => ['required', 'string', $this->enumRule(DocumentKind::class)],
            'title' => ['nullable', 'string', 'max:190'],
            'file' => ['required', 'file', 'max:'.$maxKb],
        ];
    }
}
