<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * MTG-03 — one message of a thread. `author_side` is `staff` (council) or `party` (owner / investor side).
 *
 * @property int $id
 * @property string $uuid
 * @property int $thread_id
 * @property ?int $user_id
 * @property string $author_side
 * @property ?string $body
 */
class ThreadMessage extends Model
{
    use HasUuid;

    public const SIDE_STAFF = 'staff';

    public const SIDE_PARTY = 'party';

    protected $fillable = ['thread_id', 'user_id', 'author_side', 'body'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Attachments — uploaded through DocumentService (encrypted at rest, scanned, signed URLs only). */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
