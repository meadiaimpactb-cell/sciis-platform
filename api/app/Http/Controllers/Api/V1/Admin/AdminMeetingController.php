<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InterestStatus;
use App\Enums\MeetingParticipantRole;
use App\Enums\MeetingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MeetingMinutesRequest;
use App\Http\Requests\Admin\MeetingRequest;
use App\Http\Resources\MeetingResource;
use App\Models\Interest;
use App\Models\Meeting;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\MeetingInviteNotification;
use App\Services\AuditLogger;
use App\Services\InterestService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** MTG-01 (schedule + .ics), MTG-02 (minutes + actions). */
class AdminMeetingController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly InterestService $interests,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /admin/meetings */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request);

        $paginator = Meeting::query()
            ->with(['opportunity', 'participants.user', 'organizer', 'actions'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('opportunity'), fn (Builder $q) => $q->whereHas('opportunity', fn ($o) => $o->where('uuid', $request->input('opportunity'))))
            ->orderByDesc('starts_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => MeetingResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /admin/meetings — MTG-01 */
    public function store(MeetingRequest $request): JsonResponse
    {
        $this->assertCan($request);
        $data = $request->validated();
        $opportunity = Opportunity::query()->where('uuid', $data['opportunity_uuid'])->firstOrFail();

        $meeting = DB::transaction(function () use ($data, $opportunity, $request): Meeting {
            $meeting = Meeting::query()->create([
                'opportunity_id' => $opportunity->id,
                'interest_id' => $data['interest_id'] ?? null,
                'title' => $data['title'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'location' => $data['location'] ?? null,
                'video_url' => $data['video_url'] ?? null,
                'organizer_user_id' => $request->user()->id,
                'status' => MeetingStatus::Scheduled,
            ]);

            $this->syncParticipants($meeting, $opportunity, (array) $data['participant_user_ids']);
            $this->audit->log('meeting.created', $meeting, null, ['title' => $meeting->title, 'starts_at' => $meeting->starts_at?->toIso8601String()], $request->user());

            if (! empty($data['interest_id'])) {
                $interest = Interest::query()->find($data['interest_id']);
                if ($interest && $interest->status === InterestStatus::Connected) {
                    $this->interests->transition($interest, InterestStatus::Meeting, $request->user());
                }
            }

            return $meeting;
        });

        $this->sendInvites($meeting, false);

        return response()->json(['data' => (new MeetingResource($meeting->load(['opportunity', 'participants.user', 'organizer', 'actions'])))->resolve($request)], 201);
    }

    /** PATCH /admin/meetings/{id} */
    public function update(MeetingRequest $request, Meeting $meeting): JsonResponse
    {
        $this->assertCan($request);
        $data = $request->validated();
        $before = $meeting->only(['title', 'starts_at', 'ends_at', 'location', 'video_url', 'status']);

        DB::transaction(function () use ($meeting, $data, $request): void {
            $meeting->fill(collect($data)->except(['participant_user_ids', 'opportunity_uuid'])->all())->save();
            if (array_key_exists('participant_user_ids', $data)) {
                $this->syncParticipants($meeting, $meeting->opportunity, (array) $data['participant_user_ids']);
            }
            $this->audit->log('meeting.updated', $meeting, null, $meeting->getChanges(), $request->user());
        });

        $changedTime = $before['starts_at'] != $meeting->starts_at || $before['ends_at'] != $meeting->ends_at;
        if ($changedTime || array_key_exists('participant_user_ids', $data)) {
            $this->sendInvites($meeting, true);
        }

        return $this->ok((new MeetingResource($meeting->load(['opportunity', 'participants.user', 'organizer', 'actions'])))->resolve($request));
    }

    /** POST /admin/meetings/{id}/minutes — MTG-02 */
    public function minutes(MeetingMinutesRequest $request, Meeting $meeting): JsonResponse
    {
        $this->assertCan($request);
        $data = $request->validated();

        DB::transaction(function () use ($meeting, $data, $request): void {
            $meeting->fill(['minutes' => $data['minutes'], 'status' => MeetingStatus::Held])->save();

            if (array_key_exists('attended_user_ids', $data)) {
                $attended = (array) $data['attended_user_ids'];
                foreach ($meeting->participants as $p) {
                    $p->attended = in_array($p->user_id, $attended, true);
                    $p->save();
                }
            }

            $meeting->actions()->delete();
            foreach ((array) ($data['actions'] ?? []) as $action) {
                $meeting->actions()->create(['description' => $action['description'], 'owner_user_id' => $action['owner_user_id'] ?? null, 'due_at' => $action['due_at'] ?? null]);
            }

            $this->audit->log('meeting.minutes_recorded', $meeting, null, ['actions' => count((array) ($data['actions'] ?? []))], $request->user());
        });

        return $this->ok((new MeetingResource($meeting->load(['opportunity', 'participants.user', 'organizer', 'actions'])))->resolve($request));
    }

    /** @param array<int, int> $userIds */
    private function syncParticipants(Meeting $meeting, Opportunity $opportunity, array $userIds): void
    {
        $meeting->participants()->delete();
        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            $role = match (true) {
                $user->id === $opportunity->owner_user_id => MeetingParticipantRole::Owner,
                $user->isStaff() => MeetingParticipantRole::Council,
                default => MeetingParticipantRole::Investor,
            };
            $meeting->participants()->create(['user_id' => $user->id, 'role' => $role]);
        }
    }

    private function sendInvites(Meeting $meeting, bool $isUpdate): void
    {
        $meeting->load(['participants.user', 'organizer', 'opportunity']);
        foreach ($meeting->participants as $participant) {
            $this->notifications->send($participant->user, new MeetingInviteNotification($meeting, $isUpdate));
        }
        $meeting->ics_sent_at = now();
        $meeting->save();
    }

    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('meetings.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
