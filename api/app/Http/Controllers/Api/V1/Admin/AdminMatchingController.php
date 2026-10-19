<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InfoRequestStatus;
use App\Enums\InterestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NominateRequest;
use App\Http\Requests\Admin\ReplyInfoRequestRequest;
use App\Http\Requests\Admin\TeaserRequest;
use App\Http\Requests\Admin\TransitionInterestRequest;
use App\Http\Resources\InfoRequestResource;
use App\Http\Resources\InterestResource;
use App\Http\Resources\NominationResource;
use App\Models\InfoRequest;
use App\Models\Interest;
use App\Models\Opportunity;
use App\Notifications\InfoRequestAnsweredNotification;
use App\Notifications\InfoRequestForwardedNotification;
use App\Services\AuditLogger;
use App\Services\InterestService;
use App\Services\MatchingService;
use App\Services\NotificationService;
use App\Services\TeaserService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** MAT-01..05 (teaser, candidates, nominations), interests pipeline, info-request routing (OPP-05/MSG-01). */
class AdminMatchingController extends Controller
{
    public function __construct(
        private readonly MatchingService $matching,
        private readonly TeaserService $teasers,
        private readonly InterestService $interests,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /admin/opportunities/{uuid}/teaser — MAT-05 */
    public function teaser(TeaserRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('manageTeaser', $opportunity);
        $teaser = $this->teasers->upsert($opportunity, $request->user(), $request->validated('summary_ar'), $request->validated('summary_en'), (array) $request->validated('highlights', []));

        return $this->ok($teaser->toArray());
    }

    /** POST /admin/opportunities/{uuid}/teaser/approve — council approval. */
    public function approveTeaser(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('manageTeaser', $opportunity);
        $teaser = $this->teasers->approveByCouncil($opportunity, $request->user());

        return $this->ok($teaser->toArray());
    }

    /** GET /admin/opportunities/{uuid}/candidates — MAT-02 ranked, MAT-04 COI excluded. */
    public function candidates(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('nominate', $opportunity);

        $rows = $this->matching->candidates($opportunity, (int) $request->integer('limit', 50))->map(fn (array $row): array => [
            'user' => ['id' => $row['user']->id, 'uuid' => $row['user']->uuid, 'name' => $row['user']->name, 'type' => $row['user']->type->value, 'type_label' => $row['user']->type->label()],
            'match_score' => $row['score'],
            'matched_criteria' => array_map(fn (string $c): array => ['key' => $c, 'label' => __('enums.match_criteria.'.$c)], $row['matched']),
            'already_nominated' => $row['already_nominated'],
        ])->values()->all();

        return $this->ok(['criteria' => MatchingService::CRITERIA, 'max_nominations' => (int) setting('opportunity.max_nominations_per_opportunity', 5), 'current_nominations' => $opportunity->nominations()->count(), 'candidates' => $rows]);
    }

    /** POST /admin/opportunities/{uuid}/nominations — MAT-01/03 */
    public function nominate(NominateRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('nominate', $opportunity);
        $nominations = $this->matching->nominate($opportunity, $request->validated('investor_user_ids'), $request->validated('rationale'), $request->user());

        return response()->json(['data' => NominationResource::collection($nominations->each->load(['opportunity', 'investor']))->resolve($request)], 201);
    }

    /** GET /admin/interests */
    public function interests(Request $request): JsonResponse
    {
        if (! $request->user()->can('nominations.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }

        $paginator = Interest::query()
            ->with(['opportunity', 'investor', 'declineReason'])
            ->when($request->filled('status'), fn (Builder $q) => $q->whereIn('status', (array) $request->input('status')))
            ->when($request->filled('opportunity'), fn (Builder $q) => $q->whereHas('opportunity', fn ($o) => $o->where('uuid', $request->input('opportunity'))))
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => InterestResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /admin/interests/{id}/transition */
    public function transitionInterest(TransitionInterestRequest $request, Interest $interest): JsonResponse
    {
        $this->authorize('transition', $interest);
        $interest = $this->interests->transition($interest, InterestStatus::from($request->validated('to')), $request->user(), $request->validated('reason'));

        return $this->ok((new InterestResource($interest->load(['opportunity', 'investor'])))->resolve($request));
    }

    /** GET /admin/info-requests */
    public function infoRequests(Request $request): JsonResponse
    {
        if (! $request->user()->can('nominations.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }

        $paginator = InfoRequest::query()
            ->with(['opportunity', 'investor', 'handler'])
            ->when($request->filled('status'), fn (Builder $q) => $q->whereIn('status', (array) $request->input('status')))
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => InfoRequestResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /admin/info-requests/{id}/forward — forwards to owner WITHOUT investor identity (MSG-01). */
    public function forwardInfoRequest(Request $request, InfoRequest $infoRequest): JsonResponse
    {
        if (! $request->user()->can('nominations.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }

        $infoRequest->fill(['status' => InfoRequestStatus::Processing, 'handled_by' => $request->user()->id, 'forwarded_to_owner_at' => now()])->save();
        $this->audit->log('info_request.forwarded', $infoRequest, null, null, $request->user());
        $this->notifications->send($infoRequest->opportunity->owner, new InfoRequestForwardedNotification($infoRequest));

        return $this->ok((new InfoRequestResource($infoRequest))->resolve($request));
    }

    /** POST /admin/info-requests/{id}/reply — council answers directly. */
    public function replyInfoRequest(ReplyInfoRequestRequest $request, InfoRequest $infoRequest): JsonResponse
    {
        if (! $request->user()->can('nominations.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }

        $infoRequest->fill(['status' => InfoRequestStatus::Answered, 'handled_by' => $request->user()->id, 'owner_answer' => $request->validated('answer'), 'answered_at' => now()])->save();
        $this->audit->log('info_request.replied', $infoRequest, null, null, $request->user());
        $this->notifications->send($infoRequest->investor, new InfoRequestAnsweredNotification($infoRequest));

        return $this->ok((new InfoRequestResource($infoRequest))->resolve($request));
    }
}
