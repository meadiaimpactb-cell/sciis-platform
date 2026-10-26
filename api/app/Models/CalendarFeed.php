<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * MTG-05 — personal iCal subscription feed. Looked up by `token_hash`; `token` is encrypted (shown again to its owner).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $token
 * @property ?Carbon $last_accessed_at
 */
class CalendarFeed extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'token', 'last_accessed_at'];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_accessed_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
