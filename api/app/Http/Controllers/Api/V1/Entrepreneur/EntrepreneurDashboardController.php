<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Entrepreneur;

use App\Enums\DocumentKind;
use App\Enums\InfoRequestStatus;
use App\Enums\InterestStatus;
use App\Enums\MeetingStatus;
use App\Enums\OpportunityStatus;
use App\Enums\OwnerDecision;
use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Entrepreneur\Concerns\BuildsChecklist;
use App\Http\Controllers\Controller;
use App\Http\Requests\Entrepreneur\AnswerInfoRequestRequest;
use App\Http\Requests\Entrepreneur\DecideAccessRequest;
use App\Http\Requests\Entrepreneur\RevokeAccessRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\InfoRequestResource;
use App\Http\Resources\InterestResource;
use App\Http\Resources\MeetingResource;
use App\Http\Resources\OpportunityCardResource;
use App\Models\Document;
use App\Models\InfoRequest;
use App\Models\Interest;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\Opportunity;
use App\Notifications\InfoRequestAnsweredNotification;
use App\Services\AuditLogger;
use App\Services\InterestService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ENT-04 (dashboard + required now), ENT-06 (access requests), OPP-05 (info requests), MTG (meetings). */
class EntrepreneurDashboardController extends Controller
{
    use BuildsChecklist;

    public function __construct(
        private readonly InterestService $interests,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * GET /entrepreneur/dashboard
     *
     * `required_now[]` items: `{id, opportunity_uuid, opportunity_name, title, note, due_at, action, kind, type}` where
     * action ∈ complete|upload|approve_teaser|decide_access|answer_info and kind ∈ field|document|teaser|access_request|info_request.
     * Also: `opportunities[].completion_percent`, `documents[]` (+opportunity_uuid), `preview` (card of the main
     * opportunity), `info_requests_open`, `meetings_upcoming`; legacy keys (`pending_info_requests`, `completion`, `counters`) kept.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $opportunities = Opportunity::query()->ofAccount($user) // ACC-08
            ->with(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'organization', 'completionRequests', 'teaser', 'fundingRange', 'latestDecision'])
            ->latest('updated_at')
            ->get();
        $ids = $opportunities->pluck('id');

        $checklists = $opportunities->mapWithKeys(fn (Opportunity $o): array => [$o->id => $this->buildChecklist($o)]);

        $requiredNow = $opportunities->flatMap(function (Opportunity $o) use ($checklists): array {
            $items = [];
            foreach ($o->completionRequests->whereNull('resolved_at') as $c) {
                foreach (array_values((array) $c->items) as $idx => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $isDoc = ! empty($item['document_kind']);
                    $kind = $isDoc ? (string) $item['document_kind'] : null;
                    $field = $isDoc ? null : (string) ($item['field'] ?? '');
                    $fieldLabel = $field !== null && __("validation.attributes.{$field}") !== "validation.attributes.{$field}" ? __("validation.attributes.{$field}") : $field;
                    $items[] = [
                        'id' => $c->id * 100 + $idx,
                        'type' => 'completion_request',
                        'completion_request_id' => $c->id,
                        'opportunity_uuid' => $o->uuid,
                        'opportunity_name' => $o->name,
                        'title' => $isDoc
                            ? __('messages.dashboard.upload_document', ['kind' => DocumentKind::tryFrom($kind)?->label() ?? $kind])
                            : __('messages.dashboard.complete_field', ['field' => $fieldLabel]),
                        'note' => $item['note'] ?? null,
                        'field' => $field,
                        'document_kind' => $kind,
                        'items' => $c->items,
                        'due_at' => $c->due_at?->toIso8601String(),
                        'action' => $isDoc ? 'upload' : 'complete',
                        'kind' => $isDoc ? 'document' : 'field',
                    ];
                }
            }
            if ($o->status === OpportunityStatus::Draft) {
                $items[] = [
                    'id' => 9_000_000 + $o->id,
                    'type' => 'draft',
                    'opportunity_uuid' => $o->uuid,
                    'opportunity_name' => $o->name,
                    'title' => __('messages.dashboard.complete_draft'),
                    'note' => __('messages.dashboard.completion_percent', ['percent' => $checklists[$o->id]['completion_percent']]),
                    'due_at' => null,
                    'action' => 'complete',
                    'kind' => 'field',
                ];
            }
            if ($o->teaser && $o->teaser->approved_by_council_at && ! $o->teaser->approved_by_owner_at) {
                $items[] = [
                    'id' => 9_100_000 + $o->id,
                    'type' => 'teaser_approval',
                    'opportunity_uuid' => $o->uuid,
                    'opportunity_name' => $o->name,
                    'teaser_version' => $o->teaser->version,
                    'title' => __('messages.dashboard.approve_teaser'),
                    'note' => __('messages.dashboard.approve_teaser_note'),
                    'due_at' => null,
                    'action' => 'approve_teaser',
                    'kind' => 'teaser',
                ];
            }

            return $items;
        })->values();

        $accessRequests = Interest::query()
            ->with(['opportunity', 'investor.investorProfile'])
            ->whereIn('opportunity_id', $ids)
            ->where('status', InterestStatus::AwaitingOwnerApproval->value)
            ->latest('id')
            ->get();

        foreach ($accessRequests as $interest) {
            $requiredNow->push([
                'id' => 8_000_000 + $interest->id,
                'type' => 'access_request',
                'opportunity_uuid' => $interest->opportunity?->uuid,
                'opportunity_name' => $interest->opportunity?->name,
                'title' => __('messages.dashboard.decide_access'),
                'note' => null,
                'due_at' => $interest->owner_decision_due_at?->toIso8601String(),
                'action' => 'decide_access',
                'kind' => 'access_request',
            ]);
        }

        $openInfo = InfoRequest::query()->with('opportunity')->whereIn('opportunity_id', $ids)->whereNotNull('forwarded_to_owner_at')->where('status', InfoRequestStatus::Processing->value)->get();
        foreach ($openInfo as $info) {
            $requiredNow->push([
                'id' => 7_000_000 + $info->id,
                'type' => 'info_request',
                'opportunity_uuid' => $info->opportunity?->uuid,
                'opportunity_name' => $info->opportunity?->name,
                'title' => __('messages.dashboard.answer_info', ['subject' => $info->subject]),
                'note' => null,
                'due_at' => null,
                'action' => 'answer_info',
                'kind' => 'info_request',
            ]);
        }

        // MTG-02 — open meeting follow-up actions assigned to the owner.
        $meetingActions = MeetingAction::query()->with('meeting.opportunity')->where('owner_user_id', $user->id)->whereNull('done_at')->orderBy('due_at')->limit(20)->get();
        foreach ($meetingActions as $action) {
            $requiredNow->push([
                'id' => 6_000_000 + $action->id,
                'type' => 'meeting_action',
                'meeting_action_id' => $action->id,
                'opportunity_uuid' => $action->meeting?->opportunity?->uuid,
                'opportunity_name' => $action->meeting?->opportunity?->name,
                'title' => __('messages.dashboard.task_meeting_action', ['description' => $action->description]),
                'note' => $action->meeting?->title,
                'due_at' => $action->due_at?->toIso8601String(),
                'action' => 'meeting_action',
                'kind' => 'meeting_action',
            ]);
        }

        $documents = Document::query()
            ->where('documentable_type', (new Opportunity)->getMorphClass())
            ->whereIn('documentable_id', $ids)
            ->orderByDesc('id')
            ->get();
        $uuidById = $opportunities->pluck('uuid', 'id');

        $primary = $opportunities->first(fn (Opportunity $o): bool => ! $o->status->isTerminal()) ?? $opportunities->first();
        $upcomingMeetings = Meeting::query()->whereIn('opportunity_id', $ids)->where('status', MeetingStatus::Scheduled->value)->where('starts_at', '>', now())->count();

        return $this->ok([
            'opportunities' => $opportunities->map(fn (Opportunity $o): array => [
                'uuid' => $o->uuid, 'reference_no' => $o->reference_no, 'name' => $o->name, 'status' => $o->status->value, 'status_label' => $o->status->label(),
                'status_color' => $o->status->color(), 'sector' => $o->sector?->label(), 'sector_term' => $o->sector ? ['id' => $o->sector->id, 'key' => $o->sector->key, 'label' => $o->sector->label(), 'label_ar' => $o->sector->label_ar, 'label_en' => $o->sector->label_en] : null,
                'submitted_at' => $o->submitted_at?->toIso8601String(), 'updated_at' => $o->updated_at?->toIso8601String(),
                'overdue' => $o->overdueKind(), 'is_overdue' => $o->overdueKind() !== null,
                'sla_due_at' => $o->slaDueAt()?->toIso8601String(), 'sla_days_left' => $o->slaDaysLeft(),
                'completion_percent' => $checklists[$o->id]['completion_percent'],
                'checklist_ready' => $checklists[$o->id]['ready'],
                'locked_sections' => $o->lockedWebSections(),
                // EVL-03 — the evaluation decision as the owner sees it (result, conditions/reasons, improvement plan).
                'evaluation_decision' => $o->latestDecision?->toOwnerArray(),
            ])->values()->all(),
            'required_now' => $requiredNow->values()->all(),
            'access_requests' => InterestResource::collection($accessRequests)->resolve($request),
            'documents' => $documents->map(fn (Document $d): array => (new DocumentResource($d))->resolve($request) + ['opportunity_uuid' => $uuidById[$d->documentable_id] ?? null])->values()->all(),
            'preview' => $primary ? (new OpportunityCardResource($primary))->resolve($request) : null,
            'info_requests_open' => $openInfo->count(),
            'meetings_upcoming' => $upcomingMeetings,
            'pending_info_requests' => $openInfo->count(),
            'completion' => $opportunities->isEmpty() ? 0 : (int) round($opportunities->filter(fn ($o) => $o->submitted_at !== null)->count() / $opportunities->count() * 100),
            'counters' => [
                'opportunities' => $opportunities->count(),
                'access_requests' => $accessRequests->count(),
                'meetings' => $upcomingMeetings,
                'documents' => $documents->count(),
            ],
        ]);
    }

    /** GET /entrepreneur/access-requests — ENT-06 (investor identity withheld in resource). */
    public function accessRequests(Request $request): JsonResponse
    {
        $paginator = Interest::query()
            ->with(['opportunity', 'investor.investorProfile', 'declineReason'])
            ->whereHas('opportunity', fn ($o) => $o->ofAccount($request->user()))
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

    /** POST /entrepreneur/access-requests/{interestId}/revoke — ENT-06: withdraw a previously approved full-file access. */
    public function revoke(RevokeAccessRequest $request, Interest $interest): JsonResponse
    {
        $this->authorize('revoke', $interest);

        $interest = $this->interests->ownerRevoke($interest, $request->user(), $request->validated('reason'));

        return $this->ok((new InterestResource($interest->load(['opportunity', 'investor', 'declineReason'])))->resolve($request));
    }

    /** GET /entrepreneur/info-requests — only forwarded ones, anonymised. */
    public function infoRequests(Request $request): JsonResponse
    {
        $paginator = InfoRequest::query()
            ->with('opportunity')
            ->whereHas('opportunity', fn ($o) => $o->ofAccount($request->user()))
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
        // §6.3 — a closed request takes no more answers.
        if ($infoRequest->status === InfoRequestStatus::Closed) {
            throw new DomainException(__('messages.info_request.already_closed'), 'info_request_closed', 422);
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
            ->whereHas('opportunity', fn ($o) => $o->ofAccount($request->user()))
            ->orderByDesc('starts_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => MeetingResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }
}
