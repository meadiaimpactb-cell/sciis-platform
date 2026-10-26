<?php

declare(strict_types=1);

namespace App\Http\Requests\Entrepreneur;

use App\Enums\DocumentKind;
use App\Http\Requests\ApiRequest;
use App\Models\Opportunity;

/**
 * DOC-01 — size and mime limits are enforced again in DocumentService from settings.
 * ENT-05 — while `needs_completion` only the document kinds listed in the open completion requests are accepted.
 */
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

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            $opportunity = $this->route('opportunity');
            $kind = (string) $this->input('kind', '');
            if ($opportunity instanceof Opportunity && $kind !== '' && ! $opportunity->isDocumentKindEditable($kind)) {
                $v->errors()->add('kind', __('messages.opportunity.locked_document_kind', ['kind' => $kind]));
                $v->errors()->add('locked_fields', __('messages.opportunity.locked_fields', ['fields' => 'document:'.$kind]));
            }
        });
    }
}
