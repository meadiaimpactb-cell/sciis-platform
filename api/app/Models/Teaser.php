<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MAT-05 — only public-sensitivity fields may appear in a teaser. */
class Teaser extends Model
{
    protected $fillable = [
        'opportunity_id', 'summary_ar', 'summary_en', 'highlights', 'version',
        'approved_by_owner_at', 'approved_by_council_at', 'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'highlights' => 'array',
            'version' => 'integer',
            'approved_by_owner_at' => 'datetime',
            'approved_by_council_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function isFullyApproved(): bool
    {
        return $this->approved_by_owner_at !== null && $this->approved_by_council_at !== null;
    }

    public function summary(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return ($locale === 'en' && $this->summary_en) ? (string) $this->summary_en : (string) $this->summary_ar;
    }
}
