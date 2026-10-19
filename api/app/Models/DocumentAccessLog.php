<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentAccessAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** INV-04 — who accessed which document, when. */
class DocumentAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['document_id', 'user_id', 'action', 'ip', 'user_agent', 'created_at'];

    protected function casts(): array
    {
        return [
            'action' => DocumentAccessAction::class,
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
