<?php

declare(strict_types=1);

namespace App\Http\Requests\Investor;

use App\Enums\DocumentKind;
use App\Enums\UserType;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * INV-01 — membership documents. Individuals: `investor_cv`; institutions: `commercial_register`,
 * `authorization_letter`, `impact_policy`. Size / mime limits are enforced again in DocumentService from settings.
 */
class UploadInvestorDocumentRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) setting('documents.max_size_mb', 25) * 1024;
        $institutional = $this->user()?->type === UserType::InvestorInstitutional;
        $kinds = array_map(fn (DocumentKind $k): string => $k->value, DocumentKind::forInvestor($institutional));

        return [
            'kind' => ['required', 'string', Rule::in($kinds)],
            'title' => ['nullable', 'string', 'max:190'],
            'file' => ['required', 'file', 'max:'.$maxKb],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['kind.in' => __('messages.documents.investor_kind_not_allowed')];
    }
}
