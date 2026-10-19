<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;

/** CMS-02 */
class CmsContentRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $req = $isCreate ? 'required' : 'sometimes';

        return [
            'slug' => [$req, 'string', 'max:160', 'regex:/^[a-z0-9\-]+$/'],
            'title_ar' => [$req, 'string', 'max:190'],
            'title_en' => ['nullable', 'string', 'max:190'],
            'body_ar' => ['nullable', 'string'],
            'body_en' => ['nullable', 'string'],
            'version' => ['nullable', 'string', 'max:20'],
            'is_published' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'category' => ['nullable', 'string', 'max:60'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'registration_url' => ['nullable', 'url', 'max:255'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
