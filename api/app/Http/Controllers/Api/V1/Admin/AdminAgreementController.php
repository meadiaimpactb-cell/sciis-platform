<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AgreementReviewDecision;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agreements\AgreementNoteRequest;
use App\Http\Requests\Agreements\CancelAgreementRequest;
use App\Http\Requests\Agreements\ReviewAgreementRequest;
use App\Http\Requests\Agreements\ReviseAgreementRequest;
use App\Http\Requests\Agreements\StoreAgreementRequest;
use App\Http\Requests\Agreements\UploadSignedAgreementRequest;
use App\Http\Resources\AgreementResource;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\Interest;
use App\Services\Agreements\AgreementService;
use App\Services\Agreements\LineDiff;
use App\Services\DocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AGR-02..05 staff side: list with status filter, draft from a template for a connection, versions + compare,
 * legal review, send for signature, wet-signed upload, archive, cancel. Gated by `agreements.manage` /
 * `agreements.approve` (never role names).
 */
class AdminAgreementController extends Controller
{
    public function __construct(private readonly AgreementService $agreements, private readonly DocumentService $documents) {}

    /** GET /admin/agreements?status=&search=&opportunity= */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage', 'agreements.approve');

        $filtered = fn (bool $withStatus): Builder => Agreement::query()
            ->when($withStatus && $request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('opportunity'), fn (Builder $q) => $q->whereHas('opportunity', fn (Builder $o) => $o->where('uuid', (string) $request->input('opportunity'))))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $term)
                    ->orWhereHas('opportunity', fn (Builder $o) => $o->where('name', 'like', $term)->orWhere('reference_no', 'like', $term))
                    ->orWhereHas('investor', fn (Builder $u) => $u->where('name', 'like', $term)));
            });

        $paginator = $filtered(true)
            ->with(['opportunity.owner', 'investor', 'currentVersion', 'template'])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        $counts = $filtered(false)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($n): int => (int) $n)->all();

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Agreement $a): array => AgreementResource::listItem($a)->resolve($request))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
                'counts' => (object) $counts,
            ],
        ]);
    }

    /** GET /admin/agreements/connections — AGR-02: connections ready for an agreement. */
    public function connections(Request $request): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage');

        $rows = $this->agreements->connectionsQuery()
            ->with(['opportunity.owner', 'investor'])
            ->orderByDesc('updated_at')->limit(200)->get()
            ->map(fn (Interest $i): array => [
                'interest_id' => $i->id,
                'status' => $i->status->value,
                'opportunity' => ['uuid' => $i->opportunity->uuid, 'name' => $i->opportunity->name, 'reference_no' => $i->opportunity->reference_no, 'status' => $i->opportunity->status->value],
                'owner' => $i->opportunity->owner ? ['id' => $i->opportunity->owner->id, 'name' => $i->opportunity->owner->name] : null,
                'investor' => $i->investor ? ['id' => $i->investor->id, 'name' => $i->investor->name] : null,
            ])->all();

        return $this->ok(['connections' => $rows]);
    }

    /** GET /admin/agreements/draft-preview?interest_id=&template_id= — placeholders with their default values. */
    public function preview(Request $request): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage');
        $request->validate(['interest_id' => ['required', 'integer'], 'template_id' => ['required', 'integer']]);

        $interest = Interest::query()->with('opportunity')->findOrFail($request->integer('interest_id'));
        $template = AgreementTemplate::query()->active()->findOrFail($request->integer('template_id'));
        $this->agreements->assertDraftable($interest);

        return $this->ok([
            'title' => trim($template->title.' — '.$interest->opportunity->name),
            'placeholders' => $this->agreements->placeholderValues($interest, $template),
        ]);
    }

    /** POST /admin/agreements — AGR-02 */
    public function store(StoreAgreementRequest $request): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage');
        $data = $request->validated();

        $agreement = $this->agreements->create(
            Interest::query()->findOrFail($data['interest_id']),
            AgreementTemplate::query()->findOrFail($data['template_id']),
            (array) ($data['placeholders'] ?? []),
            $data['title'] ?? null,
            $request->user(),
        );

        return $this->detail($request, $agreement, 201);
    }

    /** GET /admin/agreements/{uuid} */
    public function show(Request $request, Agreement $agreement): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage', 'agreements.approve');

        return $this->detail($request, $agreement);
    }

    /** POST /admin/agreements/{uuid}/versions — AGR-02: a new version of the text. */
    public function revise(ReviseAgreementRequest $request, Agreement $agreement): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage');
        $data = $request->validated();
        $this->agreements->revise($agreement, (string) $data['body'], $data['body_en'] ?? null, (string) $data['change_note'], $request->user());

        return $this->detail($request, $agreement, 201);
    }

    /** GET /admin/agreements/{uuid}/compare?from=1&to=2 — AGR-02 line-level comparison (Arabic and English texts). */
    public function compare(Request $request, Agreement $agreement): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage', 'agreements.approve');
        $request->validate(['from' => ['required', 'integer', 'min:1'], 'to' => ['required', 'integer', 'min:1']]);

        $from = $agreement->versions()->where('version', $request->integer('from'))->firstOrFail();
        $to = $agreement->versions()->where('version', $request->integer('to'))->firstOrFail();
        $body = LineDiff::compare($from->body, $to->body);
        $bodyEn = LineDiff::compare($from->body_en, $to->body_en);

        return $this->ok([
            'from' => ['version' => $from->version, 'created_at' => $from->created_at?->toIso8601String(), 'text_hash' => $from->text_hash],
            'to' => ['version' => $to->version, 'created_at' => $to->created_at?->toIso8601String(), 'text_hash' => $to->text_hash],
            'body' => $body,
            'body_en' => $bodyEn,
            'added' => $body['added'] + $bodyEn['added'],
            'removed' => $body['removed'] + $bodyEn['removed'],
        ]);
    }

    /** POST /admin/agreements/{uuid}/submit-review — AGR-03 */
    public function submitReview(AgreementNoteRequest $request, Agreement $agreement): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage');
        $this->agreements->requestReview($agreement, $request->user(), $request->validated('note'));

        return $this->detail($request, $agreement);
    }

    /** POST /admin/agreements/{uuid}/review {decision: approved|changes_requested, note} — AGR-03 */
    public function review(ReviewAgreementRequest $request, Agreement $agreement): JsonResponse
    {
        $decision = AgreementReviewDecision::from((string) $request->validated('decision'));
        $decision === AgreementReviewDecision::Approved
            ? $this->assertCan($request, 'agreements.approve')
            : $this->assertCan($request, 'agreements.manage', 'agreements.approve');

        $this->agreements->review($agreement, $request->user(), $decision, $request->validated('note'));

        return $this->detail($request, $agreement);
    }

    /** POST /admin/agreements/{uuid}/send-for-signature — AGR-05 */
    public function sendForSignature(Request $request, Agreement $agreement): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage');
        $this->agreements->sendForSignature($agreement, $request->user());

        return $this->detail($request, $agreement);
    }

    /** POST /admin/agreements/{uuid}/signed-copy (multipart `file`, PDF) — AGR-05 wet-signed alternative. */
    public function uploadSigned(UploadSignedAgreementRequest $request, Agreement $agreement): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage');
        $this->agreements->uploadWetSigned($agreement, $request->file('file'), $request->user());

        return $this->detail($request, $agreement);
    }

    /** GET /admin/agreements/{uuid}/signed-copy/download-url — signed URL, access logged (DOC-02). */
    public function signedCopyUrl(Request $request, Agreement $agreement): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage', 'agreements.approve');
        $document = $agreement->signedDocument ?? throw DomainException::make('agreements.errors.no_signed_copy', 'agreement_no_signed_copy', 404);

        return $this->ok($this->documents->temporaryUrl($document, $request->user()));
    }

    /** POST /admin/agreements/{uuid}/archive — AGR-04 */
    public function archive(Request $request, Agreement $agreement): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage');
        $this->agreements->archive($agreement, $request->user());

        return $this->detail($request, $agreement);
    }

    /** POST /admin/agreements/{uuid}/cancel {reason} */
    public function cancel(CancelAgreementRequest $request, Agreement $agreement): JsonResponse
    {
        $this->assertCan($request, 'agreements.manage');
        $this->agreements->cancel($agreement, $request->user(), (string) $request->validated('reason'));

        return $this->detail($request, $agreement);
    }

    private function detail(Request $request, Agreement $agreement, int $status = 200): JsonResponse
    {
        $fresh = Agreement::query()->findOrFail($agreement->id);

        return $this->ok(AgreementResource::staff($fresh)->resolve($request), $status);
    }

    private function assertCan(Request $request, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            if ($request->user()->can($permission)) {
                return;
            }
        }

        abort(403, __('messages.errors.forbidden'));
    }
}
