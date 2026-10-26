<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCmsContent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** CMS-02 — public content managed by staff. */
class NewsPost extends Model
{
    use HasFactory, IsCmsContent;

    protected $table = 'news_posts';

    protected $fillable = [
        'slug', 'title_ar', 'title_en', 'body_ar', 'body_en', 'version', 'is_published', 'published_at', 'sort',
        'author_name', 'author_title', 'author_bio', 'author_avatar_url', 'author_linkedin_url',
        'meta', 'created_by', 'updated_by',
    ];

    /** Author block for E-E-A-T (null when no author is set). @return array{name: string, title: ?string, bio: ?string, avatar_url: ?string, linkedin_url: ?string}|null */
    public function author(): ?array
    {
        if (! is_string($this->author_name) || trim($this->author_name) === '') {
            return null;
        }

        return [
            'name' => $this->author_name,
            'title' => $this->author_title,
            'bio' => $this->author_bio,
            'avatar_url' => $this->author_avatar_url,
            'linkedin_url' => $this->author_linkedin_url,
        ];
    }

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'sort' => 'integer',
            'meta' => 'array',
        ];
    }
}
