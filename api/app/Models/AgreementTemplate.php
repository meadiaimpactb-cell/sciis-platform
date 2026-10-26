<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AGR-01 — one row per template version (same `key`). `title` / `body` are the Arabic text, `title_en` / `body_en`
 * the English text; placeholders such as {investor_name} are filled when an agreement is drafted.
 * At most one version of a key is active; only active versions can be used for new agreements.
 */
class AgreementTemplate extends Model
{
    protected $fillable = ['key', 'title', 'title_en', 'body', 'body_en', 'description', 'change_note', 'version', 'is_active', 'legal_approved_at', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean', 'legal_approved_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(Agreement::class, 'template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
