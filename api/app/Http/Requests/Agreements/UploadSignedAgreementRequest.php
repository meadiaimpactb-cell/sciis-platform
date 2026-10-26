<?php

declare(strict_types=1);

namespace App\Http\Requests\Agreements;

use App\Services\SettingsService;

/** AGR-05 alternative — the wet-signed copy, PDF only (size limit from settings; DocumentService re-checks). */
class UploadSignedAgreementRequest extends AgreementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) (app(SettingsService::class)->maxDocumentSizeBytes() / 1024);

        return ['file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.$maxKb]];
    }
}
