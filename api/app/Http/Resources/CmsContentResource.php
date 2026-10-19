<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Pages / FAQs / news / events (CMS-02). */
class CmsContentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() ?? false;

        $data = [
            'id' => $this->when($isStaff, $this->id),
            'slug' => $this->slug,
            'title' => $this->title(),
            'body' => $this->body(),
            'version' => $this->version,
            'is_published' => $this->is_published,
            'published_at' => $this->published_at?->toIso8601String(),
            'sort' => $this->sort,
            'meta' => $this->meta,
        ];

        if ($isStaff) {
            $data += ['title_ar' => $this->title_ar, 'title_en' => $this->title_en, 'body_ar' => $this->body_ar, 'body_en' => $this->body_en];
        }
        if (isset($this->starts_at)) {
            $data += ['starts_at' => $this->starts_at?->toIso8601String(), 'ends_at' => $this->ends_at?->toIso8601String(), 'location' => $this->location, 'registration_url' => $this->registration_url];
        }
        if (isset($this->category)) {
            $data['category'] = $this->category;
        }

        return $data;
    }
}
