<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use App\Http\Requests\ApiRequest;
use App\Services\SettingsService;

/** MTG-03 — a message: text and/or up to 5 attachments (document pipeline limits: size + mime from settings). */
class PostMessageRequest extends ApiRequest
{
    public const MAX_FILES = 5;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $settings = app(SettingsService::class);
        $maxKb = (int) ceil($settings->maxDocumentSizeBytes() / 1024);

        return [
            'body' => ['nullable', 'required_without:files', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.$maxKb, 'mimetypes:'.implode(',', $settings->allowedMimes())],
        ];
    }
}
