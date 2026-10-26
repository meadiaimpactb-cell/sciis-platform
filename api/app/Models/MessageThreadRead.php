<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** MTG-03 — per-user read marker of a thread (unread = later messages of the other side). */
class MessageThreadRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['thread_id', 'user_id', 'last_read_message_id', 'last_read_at'];

    protected function casts(): array
    {
        return [
            'last_read_message_id' => 'integer',
            'last_read_at' => 'datetime',
        ];
    }
}
