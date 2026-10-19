<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** OPP-09 (P2) — private investor notes. */
class InvestorNote extends Model
{
    protected $fillable = ['user_id', 'opportunity_id', 'body', 'tags'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
