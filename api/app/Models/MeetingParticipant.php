<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingParticipantRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingParticipant extends Model
{
    protected $fillable = ['meeting_id', 'user_id', 'role', 'attended'];

    protected function casts(): array
    {
        return [
            'role' => MeetingParticipantRole::class,
            'attended' => 'boolean',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
