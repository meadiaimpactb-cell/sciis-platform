<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgreementVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['agreement_id', 'version', 'body', 'change_note', 'proposed_by', 'created_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AgreementReview::class, 'version_id');
    }
}
