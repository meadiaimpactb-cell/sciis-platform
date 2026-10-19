<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DocumentStatus;
use App\Enums\OpportunityStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignReviewerRequest;
use App\Http\Requests\Admin\CompletionRequestRequest;
use App\Http\Requests\Admin\InternalNoteRequest;
use App\Http\Requests\Admin\ReviewDocumentRequest;
use App\Http\Requests\Admin\TransitionOpportunityRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\OpportunityFullResource;
use App\Models\CompletionRequest;
use App\Models\Document;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\CompletionRequestedNotification;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\NotificationService;
use App\Services\OpportunityStateMachine;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADM-01 (transitions), ADM-02 (assignment), ADM-04 (completion requests), ADM-05 (notes), DOC review. */
class AdminOpportunityController extends Controller
{
    public function __construct(
        private readonly OpportunityStateMachine $stateMachine,
        private readonly DocumentService $documents,
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /admin/opportunities — filters: status, sector, overdue, assignee, search. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Opportunity::class);
        $user = $request->user();

        $query = Opportunity::query()
            ->with(['sector', 'owner', 'assignedReviewer', 'teaser', 'regions', 'investmentTypes', 'beneficiaryGroups', 'sdgs', 'organization', 'fundingRange', 'indicators', 'completionRequests'])
            ->whereNotNull('submitted_at')
            ->when($request->filled('status'), fn (Builder $q) => $q->whereIn('status', (array) $request->input('status')))
            ->when($request->filled('sector'), fn (Builder $q) => $q->where('sector_id', (int) $request->input('sector')))
            ->when($request->boolean('overdue'), fn (Builder $q) => $q->overdue())
            ->when($request->filled('assignee'), fn (Builder $q) => $q->where('assigned_reviewer_id', (int) $request->input('assignee')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = '%'.$request->input('search').'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $term)->orWhere('reference_no', 'like', $term));
            });

        // ACC-04: evaluators only see their assignments.
        if ($user->hasRole('evaluator') && ! $user->hasAnyRole(['super_admin', 'reviewer', 'matching_officer', 'membership_officer'])) {
            $query->whereHas('evaluationAssignments', fn (Builder $a) => $a->where('evaluator_user_id', $user->id));
        }

        $paginator = $query->orderByDesc('submitted_at')->paginate($this->perPage($request));

        return response()->json([
            'data' => OpportunityFullResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ]);
    }

    /** GET /admin/opportunities/{uuid} — full + timeline + notes. */
    public function show(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('view', $opportunity);

        return $this->ok((new OpportunityFullResource($opportunity))->resolve($request));
    }

    /** POST /admin/opportunities/{uuid}/assign — ADM-02 */
    public function assign(AssignReviewerRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('assign', $opportunity);

        $reviewer = User::query()->staff()->active()->findOrFail((int) $request->validated('reviewer_user_id'));
        if (! $reviewer->hasAnyRole(['reviewer', 'super_admin'])) {
            throw new DomainException(__('messages.opportunity.not_a_reviewer'), 'not_a_reviewer', 422);
        }

        $before = ['assigned_reviewer_id' => $opportunity->assigned_reviewer_id];
        $opportunity->assigned_reviewer_id = $reviewer->id;
        $opportunity->review_due_at ??= now()->addDays($this->settings->slaDays('review'));
        $opportunity->save();
        $this->audit->log('opportunity.assigned', $opportunity, $before, ['assigned_reviewer_id' => $reviewer->id], $request->user());

        return $this->ok(['assigned_reviewer' => ['id' => $reviewer->id, 'name' => $reviewer->name], 'review_due_at' => $opportunity->review_due_at?->toIso8601String()]);
    }

    /** POST /admin/opportunities/{uuid}/transition — ADM-01 */
    public function transition(TransitionOpportunityRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('transition', $opportunity);

        $to = OpportunityStatus::from($request->validated('to'));

        // not_eligible requires supervisor approval: reviewer may only propose when super_admin exists? Keep it simple:
        // reviewer or super_admin with permission; reason enforced by the state machine.
        $opportunity = $this->stateMachine->transition($opportunity, $to, $request->user(), $request->validated('reason'));

        return $this->ok((new OpportunityFullResource($opportunity))->resolve($request));
    }

    /** POST /admin/opportunities/{uuid}/completion-requests — ADM-04 */
    public function completionRequest(CompletionRequestRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('transition', $opportunity);

        $due = $request->validated('due_at') ? \Illuminate\Support\Carbon::parse($request->validated('due_at')) : now()->addDays($this->settings->slaDays('completion'));

        $completion = CompletionRequest::query()->create([
            'opportunity_id' => $opportunity->id,
            'requested_by' => $request->user()->id,
            'due_at' => $due,
            'items' => $request->validated('items'),
        ]);

        $this->audit->log('opportunity.completion_requested', $completion, null, ['items' => $completion->items, 'due_at' => $due->toIso8601String()], $request->user());

        if (in_array($opportunity->status, [OpportunityStatus::New, OpportunityStatus::UnderReview], true)) {
            $opportunity = $this->stateMachine->transition($opportunity, OpportunityStatus::NeedsCompletion, $request->user(), __('messages.opportunity.completion_requested'));
        }

        $this->notifications->send($opportunity->owner, new CompletionRequestedNotification($completion));

        return response()->json(['data' => ['id' => $completion->id, 'items' => $completion->items, 'due_at' => $completion->due_at?->toIso8601String(), 'status' => $opportunity->status->value]], 201);
    }

    /** POST /admin/opportunities/{uuid}/notes — ADM-05 */
    public function note(InternalNoteRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('addNote', $opportunity);

        $note = $opportunity->internalNotes()->create(['author_id' => $request->user()->id, 'body' => $request->validated('body')]);
        $this->audit->log('opportunity.note_added', $note, null, null, $request->user());

        return response()->json(['data' => ['id' => $note->id, 'author' => $request->user()->name, 'body' => $note->body, 'created_at' => $note->created_at?->toIso8601String()]], 201);
    }

    /** POST /admin/opportunities/{uuid}/documents/{docUuid}/review */
    public function reviewDocument(ReviewDocumentRequest $request, Opportunity $opportunity, Document $document): JsonResponse
    {
        $this->authorize('review', $document);
        if ((int) $document->documentable_id !== (int) $opportunity->id) {
            throw new DomainException(__('messages.errors.not_found'), 'not_found', 404);
        }

        $document = $this->documents->review($document, $request->user(), DocumentStatus::from($request->validated('status')), $request->validated('note'));

        return $this->ok((new DocumentResource($document))->resolve($request));
    }

    /** GET /admin/documents/{uuid}/download-url — staff download (audited). */
    public function documentDownloadUrl(Request $request, Document $document): JsonResponse
    {
        $this->authorize('download', $document);

        return $this->ok($this->documents->temporaryUrl($document, $request->user()));
    }
}
