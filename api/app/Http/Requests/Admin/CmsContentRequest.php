<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use App\Rules\PageSections;

/** CMS-02 — slug is optional on create (auto-generated server-side, #32). */
class CmsContentRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $req = $isCreate ? 'required' : 'sometimes';

        return [
            'slug' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/^[a-z0-9\-]+$/'],
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
            // news authors (E-E-A-T)
            'author_name' => ['nullable', 'string', 'max:120'],
            'author_title' => ['nullable', 'string', 'max:160'],
            'author_bio' => ['nullable', 'string', 'max:2000'],
            'author_avatar_url' => ['nullable', 'string', 'max:500'],
            'author_linkedin_url' => ['nullable', 'url', 'max:500'],
            // growth types: stories / partners / resources (unused keys are dropped per type by the controller)
            'link_url' => ['nullable', 'url:http,https', 'max:500'],
            'sector_ar' => ['nullable', 'string', 'max:120'],
            'sector_en' => ['nullable', 'string', 'max:120'],
            'beneficiaries' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'impact_metric_ar' => ['nullable', 'string', 'max:190'],
            'impact_metric_en' => ['nullable', 'string', 'max:190'],
            'approved_for_display' => ['nullable', 'boolean'],
            'audience' => ['nullable', 'string', 'in:investor,entrepreneur,all'],
            'meta.logo' => ['nullable', 'array'],
            'meta.logo.url' => ['required_with:meta.logo', 'string', 'max:500'],
            'meta.logo.path' => ['nullable', 'string', 'max:500'],
            'meta.logo.alt_ar' => ['nullable', 'string', 'max:190'],
            'meta.logo.alt_en' => ['nullable', 'string', 'max:190'],
            'meta.logo.width' => ['nullable', 'integer', 'min:1'],
            'meta.logo.height' => ['nullable', 'integer', 'min:1'],
            'meta.file' => ['nullable', 'array'],
            'meta.file.url' => ['required_with:meta.file', 'string', 'max:500'],
            'meta.file.path' => ['nullable', 'string', 'max:500'],
            'meta.file.name' => ['nullable', 'string', 'max:190'],
            'meta.file.size' => ['nullable', 'integer', 'min:0'],
            'meta.file.mime' => ['nullable', 'string', 'max:120'],
            // meta: free-form, with typed sub-keys for the home hero (BRD-03) and cover images
            'meta' => ['nullable', 'array'],
            'meta.hero_images' => ['nullable', 'array', 'max:3'],
            'meta.hero_images.*.url' => ['required', 'string', 'max:500'],
            'meta.hero_images.*.path' => ['nullable', 'string', 'max:500'],
            'meta.hero_images.*.alt_ar' => ['nullable', 'string', 'max:190'],
            'meta.hero_images.*.alt_en' => ['nullable', 'string', 'max:190'],
            'meta.hero_images.*.width' => ['nullable', 'integer', 'min:1'],
            'meta.hero_images.*.height' => ['nullable', 'integer', 'min:1'],
            'meta.cover' => ['nullable', 'array'],
            'meta.cover.url' => ['required_with:meta.cover', 'string', 'max:500'],
            'meta.cover.path' => ['nullable', 'string', 'max:500'],
            'meta.cover.alt_ar' => ['nullable', 'string', 'max:190'],
            'meta.cover.alt_en' => ['nullable', 'string', 'max:190'],
            'meta.cover.width' => ['nullable', 'integer', 'min:1'],
            'meta.cover.height' => ['nullable', 'integer', 'min:1'],
            // public-page marketing copy: { key: { ar, en } } (strings ≤ 2000 chars, lists ≤ 20 items)
            'meta.sections' => ['nullable', new PageSections],
        ];
    }
}
