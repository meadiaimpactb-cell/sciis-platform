<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    public const CATEGORIES = ['opportunity', 'membership', 'interest', 'meeting', 'info_request', 'payment', 'system'];

    protected $fillable = ['user_id', 'channel', 'category', 'enabled'];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
