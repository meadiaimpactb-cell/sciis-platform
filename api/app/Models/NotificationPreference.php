<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    // MTG-03 `message` — in-platform messages tied to a record.
    public const CATEGORIES = ['opportunity', 'membership', 'interest', 'meeting', 'info_request', 'message', 'payment', 'system', 'alerts', 'digest'];

    /** OPP-07 new-opportunity alerts and MSG-03 weekly digest — investors only, in-app + e-mail (no SMS). */
    public const INVESTOR_CATEGORIES = ['alerts', 'digest'];

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
