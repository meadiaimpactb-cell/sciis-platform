<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/** FRM-04 */
class TaxonomyTermRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $group = (string) $this->route('group');
        $termId = $this->route('term')?->id ?? null;
        $isCreate = $this->isMethod('POST');

        return [
            'key' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:80', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('taxonomy_terms', 'key')->where('group', $group)->ignore($termId)],
            'label_ar' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:190'],
            'label_en' => ['nullable', 'string', 'max:190'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
