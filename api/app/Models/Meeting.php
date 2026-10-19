<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** MTG-01/02 */
class Meeting extends Model
{
    protected $fillable = [
        'opportunity_id', 'interest_id', 'title', 'starts_at', 'ends_at', 'location', 'video_url',
        'organizer_user_id', 'status', 'minutes', 'ics_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => MeetingStatus::class,
            'ics_sent_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function interest(): BelongsTo
    {
        return $this->belongsTo(Interest::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(MeetingAction::class);
    }
}
