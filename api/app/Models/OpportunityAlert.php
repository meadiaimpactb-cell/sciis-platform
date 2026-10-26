<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** OPP-07 — one row per (investor, opportunity) alert sent, so a newly listed opportunity is announced only once. */
class OpportunityAlert extends Model
{
    public const SOURCE_FILTER = 'filter';

    public const SOURCE_PROFILE = 'profile';

    public $timestamps = false;

    protected $fillable = ['user_id', 'opportunity_id', 'saved_filter_id', 'source', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function savedFilter(): BelongsTo
    {
        return $this->belongsTo(SavedFilter::class);
    }
}
