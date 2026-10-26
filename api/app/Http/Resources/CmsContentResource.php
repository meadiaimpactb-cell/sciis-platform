<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Faq;
use App\Models\KnowledgeResource;
use App\Models\NewsPost;
use App\Models\Partner;
use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Pages / FAQs / news / events (CMS-02) + impact stories / partners / knowledge resources. */
class CmsContentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() ?? false;

        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title(),
            'body' => $this->body(),
            'version' => $this->version,
            'is_published' => $this->is_published,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'sort' => $this->sort,
            'meta' => $this->meta,
        ];

        if ($isStaff) {
            $data += ['title_ar' => $this->title_ar, 'title_en' => $this->title_en, 'body_ar' => $this->body_ar, 'body_en' => $this->body_en];
        }
        if (isset($this->starts_at)) {
            $data += ['starts_at' => $this->starts_at?->toIso8601String(), 'ends_at' => $this->ends_at?->toIso8601String(), 'location' => $this->location, 'registration_url' => $this->registration_url];
            // MTG-04 — the static page lazy-loads the registration form only when this is on (live status fetched client-side).
            $data['registration_enabled'] = (bool) $this->registration_enabled;
        }
        if ($this->resource instanceof Faq) {
            // FAQ clients (web PublicFaq / mobile) consume question/answer/category.
            $data += ['question' => $this->title(), 'answer' => $this->body(), 'category' => $this->category];
        }
        if ($this->resource instanceof Story) {
            $data += [
                'sector' => $this->resource->localized('sector'),
                'beneficiaries' => $this->beneficiaries,
                'impact_metric' => $this->resource->localized('impact_metric'),
                'link_url' => $this->link_url,
            ];
            if ($isStaff) {
                $data += ['sector_ar' => $this->sector_ar, 'sector_en' => $this->sector_en, 'impact_metric_ar' => $this->impact_metric_ar, 'impact_metric_en' => $this->impact_metric_en];
            }
        }
        if ($this->resource instanceof Partner) {
            $data += ['name' => $this->title(), 'link_url' => $this->link_url];
            if ($isStaff) {
                $data['approved_for_display'] = $this->approved_for_display;
            }
        }
        if ($this->resource instanceof KnowledgeResource) {
            $file = is_array($this->meta) ? ($this->meta['file'] ?? null) : null;
            $data += [
                'audience' => $this->audience,
                'link_url' => $this->link_url,
                'url' => $this->resource->url(),
                'file' => is_array($file) ? ['name' => $file['name'] ?? null, 'size' => $file['size'] ?? null, 'mime' => $file['mime'] ?? null] : null,
            ];
        }
        if ($this->resource instanceof NewsPost) {
            // E-E-A-T author block (public) + raw columns for the admin form.
            $data['author'] = $this->resource->author();
            if ($isStaff) {
                $data += [
                    'author_name' => $this->author_name, 'author_title' => $this->author_title, 'author_bio' => $this->author_bio,
                    'author_avatar_url' => $this->author_avatar_url, 'author_linkedin_url' => $this->author_linkedin_url,
                ];
            }
        }

        return $data;
    }
}
