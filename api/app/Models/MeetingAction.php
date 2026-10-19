<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAction extends Model
{
    protected $fillable = ['meeting_id', 'description', 'owner_user_id', 'due_at', 'done_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
