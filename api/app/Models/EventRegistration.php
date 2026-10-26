<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * MTG-04 — a guest's or signed-in user's registration to an event, with attendance.
 *
 * @property int $id
 * @property string $uuid
 * @property int $event_id
 * @property ?int $user_id
 * @property string $name
 * @property string $email
 * @property ?string $phone
 * @property ?string $organization
 * @property string $status
 * @property string $locale
 * @property ?Carbon $attended_at
 * @property ?Carbon $cancelled_at
 */
class EventRegistration extends Model
{
    use HasUuid;

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'event_id', 'user_id', 'name', 'email', 'phone', 'organization', 'status', 'locale', 'attended_at',
        'attendance_marked_by', 'cancelled_at', 'ip',
    ];

    protected $hidden = ['ip'];

    protected function casts(): array
    {
        return [
            'attended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Admin endpoints reference registrations by id. */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REGISTERED);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_REGISTERED;
    }
}
