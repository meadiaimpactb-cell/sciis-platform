<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\MeetingParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Meeting */
class MeetingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() ?? false;
        $viewerId = $request->user()?->id;

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
                'id' => $a->id, 'description' => $a->description, 'owner' => $a->owner?->name, 'owner_user_id' => $isStaff ? $a->owner_user_id : null, 'due_at' => $a->due_at?->toIso8601String(), 'done_at' => $a->done_at?->toIso8601String(),
                // MTG-02 — the viewer's own action / may mark it done (assignee or meeting staff).
                'is_mine' => $viewerId !== null && $a->owner_user_id === $viewerId,
                'can_toggle' => ($viewerId !== null && $a->owner_user_id === $viewerId) || ($isStaff && $request->user()->can('meetings.manage')),
            ])->values()->all(),
            'ics_sent_at' => $this->ics_sent_at?->toIso8601String(),
        ];
    }
}
