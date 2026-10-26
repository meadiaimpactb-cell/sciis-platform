<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FRM-01 (P2) — dynamic form schemas ("additional questions"), one row per published version of a form key.
 * `schema` = {questions: [...]} (see FormSchemaService); only one version per key `is_active`.
 */
class FormSchema extends Model
{
    protected $fillable = ['key', 'version', 'schema', 'is_active', 'published_by'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
