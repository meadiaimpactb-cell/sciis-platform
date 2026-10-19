<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataRequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PRV-03 — access / rectify / erase requests. */
class DataRequest extends Model
{
    protected $fillable = ['user_id', 'type', 'note', 'status', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return [
            'type' => DataRequestType::class,
            'handled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
