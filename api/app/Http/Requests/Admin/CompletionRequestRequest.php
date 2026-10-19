<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\DocumentKind;
use App\Http\Requests\ApiRequest;

/** ADM-04 */
class CompletionRequestRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.field' => ['nullable', 'string', 'max:80'],
            'items.*.document_kind' => ['nullable', 'string', $this->enumRule(DocumentKind::class)],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
            'due_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            foreach ((array) $this->input('items', []) as $i => $item) {
                if (empty($item['field']) && empty($item['document_kind'])) {
                    $v->errors()->add("items.{$i}", __('validation.custom.completion_items.field_or_document'));
                }
            }
        });
    }
}
