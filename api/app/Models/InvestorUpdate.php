<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvestorUpdateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** ENT-10 — owner update to the investors connected to the project (text, optional attachment, indicator snapshot). */
class InvestorUpdate extends Model
{
    protected $fillable = ['opportunity_id', 'author_id', 'title', 'body', 'metrics', 'status', 'sent_at', 'review_note', 'reviewed_by', 'reviewed_at'];

    protected $attributes = ['status' => 'published'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'status' => InvestorUpdateStatus::class, 'sent_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
