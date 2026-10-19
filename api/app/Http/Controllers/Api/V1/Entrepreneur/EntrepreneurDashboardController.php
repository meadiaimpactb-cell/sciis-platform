<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Entrepreneur;

use App\Enums\InfoRequestStatus;
use App\Enums\InterestStatus;
use App\Enums\MeetingStatus;
use App\Enums\OwnerDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Entrepreneur\AnswerInfoRequestRequest;
use App\Http\Requests\Entrepreneur\DecideAccessRequest;
use App\Http\Resources\InfoRequestResource;
use App\Http\Resources\InterestResource;
use App\Http\Resources\MeetingResource;
use App\Models\InfoRequest;
use App\Models\Interest;
use App\Models\Meeting;
use App\Notifications\InfoRequestAnsweredNotification;
use App\Services\AuditLogger;
use App\Services\InterestService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ENT-04 (dashboard + required now), ENT-06 (access requests), OPP-05 (info requests), MTG (meetings). */
class EntrepreneurDashboardController extends Controller
{
    public function __construct(
        private readonly InterestService $interests,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** GET /entrepreneur/dashboard */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $opportunities = $user->opportunities()->with(['sector', 'completionRequests', 'teaser'])->latest('updated_at')->get();

        $requiredNow = $opportunities->flatMap(function ($o): array {
            $items = [];
            foreach ($o->completionRequests->whereNull('resolved_at') as $c) {
                $items[] = ['type' => 'completion_request', 'opportunity_uuid' => $o->uuid, 'opportunity_name' => $o->name, 'items' => $c->items, 'due_at' => $c->due_at?->toIso8601String()];
            }
            if ($o->teaser && $o->teaser->approved_by_council_at && ! $o->teaser->approved_by_owner_at) {
                $items[] = ['type' => 'teaser_approval', 'opportunity_uuid' => $o->uuid, 'opportunity_name' => $o->name, 'teaser_version' => $o->teaser->version];
            }

            return $items;
        })->values()->all();

        $accessRequests = Interest::query()
            ->with(['opportunity', 'investor.investorProfile'])
            ->whereIn('opportunity_id', $opportunities->pluck('id'))
            ->where('status', InterestStatus::AwaitingOwnerApproval->value)
            ->latest('id')
            ->get();

        $pendingInfo = InfoRequest::query()->whereIn('opportunity_id', $opportunities->pluck('id'))->whereNotNull('forwarded_to_owner_at')->where('status', InfoRequestStatus::Processing->value)->count();

        return $this->ok([
            'opportunities' => $opportunities->map(fn ($o): array => [
                'uuid' => $o->uuid, 'reference_no' => $o->reference_no, 'name' => $o->name, 'status' => $o->status->value, 'status_label' => $o->status->label(),
                'status_color' => $o->status->color(), 'sector' => $o->sector?->label(), 'submitted_at' => $o->submitted_at?->toIso8601String(), 'updated_at' => $o->updated_at?->toIso8601String(),
                'overdue' => $o->overdueKind(),
            ])->all(),
            'required_now' => $requiredNow,
            'access_requests' => InterestResource::collection($accessRequests)->resolve($request),
            'pending_info_requests' => $pendingInfo,
            'completion' => $opportunities->isEmpty() ? 0 : (int) round($opportunities->filter(fn ($o) => $o->submitted_at !== null)->count() / $opportunities->count() * 100),
            'counters' => [
                'opportunities' => $opportunities->count(),
                'access_requests' => $accessRequests->count(),
                'meetings' => Meeting::query()->whereIn('opportunity_id', $opportunities->pluck('id'))->where('status', MeetingStatus::Scheduled->value)->count(),
            ],
        ]);
    }

    /** GET /entrepreneur/access-requests — ENT-06 (investor identity withheld in resource). */
    public function accessRequests(Request $request): JsonResponse
    {
        $paginator = Interest::query()
            ->with(['opportunity', 'investor.investorProfile', 'declineReason'])
            ->whereHas('opportunity', fn ($o) => $o->where('owner_user_id', $request->user()->id))
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => InterestResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /entrepreneur/access-requests/{interestId}/decide — ENT-06 */
    public function decide(DecideAccessRequest $request, Interest $interest): JsonResponse
    {
        $this->authorize('decide', $interest);
        $decision = $request->validated('decision') === 'approve' ? OwnerDecision::Approved : OwnerDecision::Declined;

        $interest = $this->interests->ownerDecide(
            $interest,
            $request->user(),
            $decision,
            $request->validated('reason'),
            $request->validated('decline_reason_id') !== null ? (int) $request->validated('decline_reason_id') : null,
        );

        return $this->ok((new InterestResource($interest->load(['opportunity', 'investor'])))->resolve($request));
    }

    /** GET /entrepreneur/info-requests — only forwarded ones, anonymised. */
    public function infoRequests(Request $request): JsonResponse
    {
        $paginator = InfoRequest::query()
            ->with('opportunity')
            ->whereHas('opportunity', fn ($o) => $o->where('owner_user_id', $request->user()->id))
            ->whereNotNull('forwarded_to_owner_at')
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => InfoRequestResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /entrepreneur/info-requests/{id}/answer */
    public function answer(AnswerInfoRequestRequest $request, InfoRequest $infoRequest): JsonResponse
    {
        if (! $infoRequest->opportunity->isOwnedBy($request->user()) || $infoRequest->forwarded_to_owner_at === null) {
            abort(403, __('messages.errors.forbidden'));
        }

        $infoRequest->fill(['owner_answer' => $request->validated('answer'), 'answered_at' => now(), 'status' => InfoRequestStatus::Answered])->save();
        $this->audit->log('info_request.answered', $infoRequest, null, null, $request->user());
        $this->notifications->send($infoRequest->investor, new InfoRequestAnsweredNotification($infoRequest));

        return $this->ok((new InfoRequestResource($infoRequest))->resolve($request));
    }

    /** GET /entrepreneur/meetings */
    public function meetings(Request $request): JsonResponse
    {
        $paginator = Meeting::query()
            ->with(['opportunity', 'participants.user', 'organizer', 'actions'])
            ->whereHas('opportunity', fn ($o) => $o->where('owner_user_id', $request->user()->id))
            ->orderByDesc('starts_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => MeetingResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }
}
