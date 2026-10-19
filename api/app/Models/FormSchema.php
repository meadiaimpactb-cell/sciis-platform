<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** FRM-01 (P2) — dynamic form schemas. */
class FormSchema extends Model
{
    protected $fillable = ['key', 'version', 'schema', 'is_active'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
