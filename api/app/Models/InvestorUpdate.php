<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ENT-10 (P2) */
class InvestorUpdate extends Model
{
    protected $fillable = ['opportunity_id', 'author_id', 'body', 'metrics', 'sent_at', 'reviewed_at'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'sent_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
