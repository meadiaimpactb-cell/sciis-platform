<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * MTG-03 — one thread per record (opportunity file ↔ owner, interest / nomination ↔ investor), always with the council.
 *
 * @property int $id
 * @property string $uuid
 * @property string $subject_type
 * @property int $subject_id
 * @property ?Carbon $last_message_at
 */
class MessageThread extends Model
{
    use HasUuid;

    protected $fillable = ['subject_type', 'subject_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ThreadMessage::class, 'thread_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ThreadMessage::class, 'thread_id')->latestOfMany();
    }

    public function reads(): HasMany
    {
        return $this->hasMany(MessageThreadRead::class, 'thread_id');
    }
}
