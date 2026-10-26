<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meetings\UpdateMeetingActionRequest;
use App\Models\MeetingAction;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

/** MTG-02 — meeting follow-up actions: the assignee (any portal) or meeting staff set / clear `done_at`. */
class MeetingActionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** PATCH /meeting-actions/{id} {done: bool} */
    public function update(UpdateMeetingActionRequest $request, MeetingAction $meetingAction): JsonResponse
    {
        $this->authorize('update', $meetingAction);

        $done = $request->boolean('done');
        $before = ['done_at' => $meetingAction->done_at?->toIso8601String()];

        if ($done !== ($meetingAction->done_at !== null)) {
            $meetingAction->done_at = $done ? now() : null;
            $meetingAction->save();
            $this->audit->log($done ? 'meeting_action.done' : 'meeting_action.reopened', $meetingAction, $before, ['done_at' => $meetingAction->done_at?->toIso8601String()], $request->user());
        }

        $meetingAction->loadMissing(['owner', 'meeting.opportunity']);

        return $this->ok(self::payload($meetingAction, $request->user()?->id));
    }

    /**
     * Shape shared with the dashboards' task lists.
     *
     * @return array<string, mixed>
     */
    public static function payload(MeetingAction $a, ?int $viewerId): array
    {
        return [
            'id' => $a->id,
            'description' => $a->description,
            'owner' => $a->owner?->name,
            'due_at' => $a->due_at?->toIso8601String(),
            'done_at' => $a->done_at?->toIso8601String(),
            'is_mine' => $viewerId !== null && $a->owner_user_id === $viewerId,
            'meeting' => $a->meeting ? ['id' => $a->meeting->id, 'title' => $a->meeting->title, 'starts_at' => $a->meeting->starts_at?->toIso8601String()] : null,
            'opportunity' => $a->meeting?->opportunity ? ['uuid' => $a->meeting->opportunity->uuid, 'name' => $a->meeting->opportunity->name, 'reference_no' => $a->meeting->opportunity->reference_no] : null,
        ];
    }
}
