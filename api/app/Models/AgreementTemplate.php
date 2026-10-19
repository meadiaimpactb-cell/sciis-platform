<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** AGR (P2) */
class AgreementTemplate extends Model
{
    protected $fillable = ['key', 'title', 'body', 'version', 'is_active', 'legal_approved_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean', 'legal_approved_at' => 'datetime'];
    }
}
