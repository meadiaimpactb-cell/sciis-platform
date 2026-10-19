<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** P2 */
class SavedFilter extends Model
{
    protected $fillable = ['user_id', 'name', 'filters', 'notify'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'notify' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
