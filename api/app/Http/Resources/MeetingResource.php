<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MeetingAction;
use App\Models\MeetingParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Meeting */
class MeetingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'opportunity' => ['uuid' => $this->opportunity->uuid, 'name' => $this->opportunity->name, 'reference_no' => $this->opportunity->reference_no],
            'interest_id' => $this->interest_id,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'location' => $this->location,
            'video_url' => $this->video_url,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'organizer' => $this->organizer ? ['name' => $this->organizer->name] : null,
            'participants' => $this->participants->map(fn (MeetingParticipant $p): array => [
                'user_id' => $isStaff ? $p->user_id : null,
                'name' => $p->user?->name,
                'role' => $p->role->value,
                'role_label' => $p->role->label(),
                'attended' => $p->attended,
            ])->values()->all(),
            'minutes' => $this->when($isStaff || $this->status->value === 'held', $this->minutes),
            'actions' => $this->actions->map(fn (MeetingAction $a): array => [
                'id' => $a->id, 'description' => $a->description, 'owner' => $a->owner?->name, 'due_at' => $a->due_at?->toIso8601String(), 'done_at' => $a->done_at?->toIso8601String(),
            ])->values()->all(),
            'ics_sent_at' => $this->ics_sent_at?->toIso8601String(),
        ];
    }
}
