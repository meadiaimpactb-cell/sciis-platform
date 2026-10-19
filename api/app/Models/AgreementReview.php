<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgreementReview extends Model
{
    protected $fillable = ['version_id', 'reviewer_id', 'decision', 'note'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AgreementVersion::class, 'version_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
