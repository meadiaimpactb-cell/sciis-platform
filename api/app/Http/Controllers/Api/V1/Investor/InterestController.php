<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Enums\DocumentAccessAction;
use App\Enums\InfoRequestStatus;
use App\Enums\NominationResponse;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Investor\CloseInterestRequest;
use App\Http\Requests\Investor\ExpressInterestRequest;
use App\Http\Requests\Investor\InfoRequestRequest;
use App\Http\Requests\Investor\RespondNominationRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\InfoRequestResource;
use App\Http\Resources\InterestResource;
use App\Http\Resources\MeetingResource;
use App\Http\Resources\NominationResource;
use App\Models\Document;
use App\Models\InfoRequest;
use App\Models\Interest;
use App\Models\Meeting;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\InterestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OPP-05 (interest + info requests), INV-04 (documents w/ access log), MAT-01 (nomination response),
 * ENT-06 (NDA before full file), MTG (investor meetings).
 */
class InterestController extends Controller
{
    public function __construct(
        private readonly InterestService $interests,
        private readonly DocumentService $documents,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /opportunities/{uuid}/interest */
    public function express(ExpressInterestRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('interact', $opportunity);
        $interest = $this->interests->express($opportunity, $request->user(), $request->validated('note'));

        return response()->json(['data' => (new InterestResource($interest->load(['opportunity', 'investor'])))->resolve($request), 'message' => __('messages.interest.sent')], 201);
    }

    /** GET /investor/interests */
    public function index(Request $request): JsonResponse
    {
        // ACC-08 — an organisation account lists the interests of all its colleagues.
        $paginator = Interest::query()->whereIn('investor_user_id', $request->user()->organizationPeerIds())->with(['opportunity', 'declineReason'])->latest('id')->paginate($this->perPage($request));

        return response()->json([
            'data' => InterestResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** GET /investor/interests/{id} */
    public function show(Request $request, Interest $interest): JsonResponse
    {
        $this->authorize('view', $interest);

        return $this->ok((new InterestResource($interest->load(['opportunity', 'declineReason', 'investor'])))->resolve($request));
    }

    /** POST /investor/interests/{id}/close */
    public function close(CloseInterestRequest $request, Interest $interest): JsonResponse
    {
        $this->authorize('close', $interest);
        $interest = $this->interests->closeByInvestor($interest, $request->user(), $request->validated('reason_id') !== null ? (int) $request->validated('reason_id') : null, $request->validated('note'));

        return $this->ok((new InterestResource($interest->load(['opportunity'])))->resolve($request));
    }

    /** POST /opportunities/{uuid}/info-requests — OPP-05 */
    public function infoRequest(InfoRequestRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('interact', $opportunity);

        $info = InfoRequest::query()->create([
            'opportunity_id' => $opportunity->id,
            'investor_user_id' => $request->user()->id,
        ] + $request->validated());

        $this->audit->log('info_request.sent', $info, null, ['subject' => $info->subject], $request->user());

        return response()->json(['data' => (new InfoRequestResource($info->load('opportunity')))->resolve($request), 'message' => __('messages.info_request.sent')], 201);
    }

    /** GET /investor/info-requests */
    public function infoRequests(Request $request): JsonResponse
    {
        $paginator = $request->user()->infoRequests()->with('opportunity')->latest('id')->paginate($this->perPage($request));

        return response()->json([
            'data' => InfoRequestResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /investor/info-requests/{id}/close — §6.3: the requesting investor closes their own answered request. */
    public function closeInfoRequest(Request $request, InfoRequest $infoRequest): JsonResponse
    {
        if ($infoRequest->investor_user_id !== $request->user()->id) {
            abort(403, __('messages.errors.forbidden'));
        }
        if ($infoRequest->isClosed()) {
            throw new DomainException(__('messages.info_request.already_closed'), 'info_request_closed', 422);
        }
        if ($infoRequest->status !== InfoRequestStatus::Answered) {
            throw new DomainException(__('messages.info_request.close_after_answer'), 'info_request_not_answered', 422);
        }

        $infoRequest->close($request->user());

        return $this->ok((new InfoRequestResource($infoRequest->load('opportunity')))->resolve($request));
    }

    /** GET /investor/nominations */
    public function nominations(Request $request): JsonResponse
    {
        // ACC-08 — an organisation account lists the nominations of all its colleagues.
        $paginator = Nomination::query()->whereIn('investor_user_id', $request->user()->organizationPeerIds())->with(['opportunity.sector', 'opportunity.regions', 'opportunity.investmentTypes', 'opportunity.beneficiaryGroups', 'opportunity.organization', 'opportunity.teaser', 'opportunity.fundingRange', 'declineReason'])->latest('sent_at')->paginate($this->perPage($request));

        return response()->json([
            'data' => NominationResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /investor/nominations/{id}/respond — MAT-01 */
    public function respondNomination(RespondNominationRequest $request, Nomination $nomination): JsonResponse
    {
        // ACC-08 — a colleague with "can act" answers for the organisation; read-only colleagues cannot.
        if (! in_array($nomination->investor_user_id, $request->user()->organizationPeerIds(), true) || ! $request->user()->canActForOrganization()) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }
        if ($nomination->response !== null) {
            throw new DomainException(__('messages.nomination.already_responded'), 'already_responded', 422);
        }

        $response = NominationResponse::from($request->validated('response'));
        $nomination->fill([
            'response' => $response,
            'decline_reason_id' => $request->validated('decline_reason_id'),
            'decline_note' => $request->validated('note'),
            'responded_at' => now(),
        ])->save();

        $this->audit->log('nomination.responded', $nomination, null, ['response' => $response->value], $request->user());

        $interest = null;
        if ($response === NominationResponse::Interested) {
            $interest = $this->interests->express($nomination->opportunity, $request->user(), $request->validated('note'), $nomination);
        }

        return $this->ok([
            'nomination' => (new NominationResource($nomination->load('opportunity')))->resolve($request),
            'interest' => $interest ? (new InterestResource($interest->load('opportunity')))->resolve($request) : null,
        ]);
    }

    /** GET /opportunities/{uuid}/documents — granted only (DOC-02). */
    public function documents(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('viewDocuments', $opportunity);
        $docs = $opportunity->documents()->clean()->where('status', '!=', 'rejected')->orderByDesc('id')->get();

        foreach ($docs as $doc) {
            $this->documents->logAccess($doc, $request->user(), DocumentAccessAction::View);
        }

        return response()->json(['data' => DocumentResource::collection($docs)->resolve($request)]);
    }

    /** GET /documents/{uuid}/download-url → {url, expires_at} (DOC-02, INV-04). */
    public function downloadUrl(Request $request, Document $document): JsonResponse
    {
        $this->authorize('download', $document);

        return $this->ok($this->documents->temporaryUrl($document, $request->user()));
    }

    /** GET /investor/meetings */
    public function meetings(Request $request): JsonResponse
    {
        $paginator = Meeting::query()
            ->with(['opportunity', 'participants.user', 'organizer', 'actions'])
            ->whereHas('participants', fn ($p) => $p->where('user_id', $request->user()->id))
            ->orderByDesc('starts_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => MeetingResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }
}
