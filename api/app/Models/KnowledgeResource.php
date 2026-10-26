<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCmsContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Curated guide / template / report — CMS type `resources`; link_url (external) or meta.file (uploaded). */
class KnowledgeResource extends Model
{
    use IsCmsContent;

    public const AUDIENCES = ['investor', 'entrepreneur', 'all'];

    protected $table = 'knowledge_resources';

    protected $fillable = [
        'slug', 'title_ar', 'title_en', 'body_ar', 'body_en', 'is_published', 'published_at', 'sort',
        'link_url', 'audience', 'meta', 'created_by', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['audience' => 'all', 'is_published' => false, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'sort' => 'integer',
            'meta' => 'array',
        ];
    }

    /** Resources for one audience also include the ones marked `all`. */
    public function scopeForAudience(Builder $query, ?string $audience): Builder
    {
        return in_array($audience, ['investor', 'entrepreneur'], true)
            ? $query->whereIn('audience', [$audience, 'all'])
            : $query;
    }

    /** External link, else the uploaded file. */
    public function url(): ?string
    {
        $file = is_array($this->meta) ? ($this->meta['file'] ?? null) : null;

        return $this->link_url ?: (is_array($file) ? ($file['url'] ?? null) : null);
    }
}
