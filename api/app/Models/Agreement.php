<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgreementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** AGR (P2) — council is NOT a party by default (doc §16.1). */
class Agreement extends Model
{
    protected $fillable = ['opportunity_id', 'interest_id', 'template_id', 'status', 'council_is_party', 'current_version_id', 'signed_document_id', 'archived_at'];

    protected function casts(): array
    {
        return ['status' => AgreementStatus::class, 'council_is_party' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function interest(): BelongsTo
    {
        return $this->belongsTo(Interest::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AgreementTemplate::class, 'template_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AgreementVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AgreementVersion::class, 'current_version_id');
    }

    public function signedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'signed_document_id');
    }
}
