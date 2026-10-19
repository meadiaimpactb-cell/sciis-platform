<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Entrepreneur;

use App\Enums\DocumentKind;
use App\Enums\InvestorAccessLevel;
use App\Enums\OpportunityStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Entrepreneur\StoreIndicatorRequest;
use App\Http\Requests\Entrepreneur\StoreOpportunityRequest;
use App\Http\Requests\Entrepreneur\UpdateOpportunityRequest;
use App\Http\Requests\Entrepreneur\UploadDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\OpportunityCardResource;
use App\Http\Resources\OpportunityDetailResource;
use App\Http\Resources\OpportunityFullResource;
use App\Models\ImpactIndicator;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\OpportunityStateMachine;
use App\Services\TeaserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ENT-01 (sections), ENT-02 (checklist), ENT-03 (submit → reference no), ENT-05 (lock per status),
 * ENT-07 (withdraw), ENT-09 (preview), DOC-01 (upload), MAT-05 (owner teaser approval).
 */
class EntrepreneurOpportunityController extends Controller
{
    public function __construct(
        private readonly OpportunityStateMachine $stateMachine,
        private readonly DocumentService $documents,
        private readonly TeaserService $teasers,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /entrepreneur/opportunities */
    public function index(Request $request): JsonResponse
    {
        $paginator = $request->user()->opportunities()
            ->with(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'sdgs', 'organization', 'teaser', 'fundingRange', 'indicators', 'completionRequests'])
            ->latest('updated_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => OpportunityFullResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** POST /entrepreneur/opportunities — ENT-01: creates a draft. */
    public function store(StoreOpportunityRequest $request): JsonResponse
    {
        $this->authorize('create', Opportunity::class);

        $opportunity = Opportunity::query()->create($request->validated() + [
            'owner_user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'status' => OpportunityStatus::Draft,
        ]);

        $this->audit->log('opportunity.created', $opportunity, null, ['name' => $opportunity->name], $request->user());

        return response()->json(['data' => (new OpportunityFullResource($opportunity))->resolve($request)], 201);
    }

    /** GET /entrepreneur/opportunities/{uuid} */
    public function show(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('view', $opportunity);

        return $this->ok((new OpportunityFullResource($opportunity))->resolve($request));
    }

    /** PATCH /entrepreneur/opportunities/{uuid} — ENT-01/05 sections (locked unless draft/needs_completion). */
    public function update(UpdateOpportunityRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('update', $opportunity);
        $data = $request->validated();

        DB::transaction(function () use ($opportunity, $data, $request): void {
            $before = $opportunity->getAttributes();

            if (isset($data['basics'])) {
                $basics = $data['basics'];
                $opportunity->fill(collect($basics)->except(['region_ids', 'investment_type_ids'])->all());
                if (array_key_exists('region_ids', $basics)) {
                    $opportunity->regions()->sync((array) $basics['region_ids']);
                }
                if (array_key_exists('investment_type_ids', $basics)) {
                    $opportunity->investmentTypes()->sync((array) $basics['investment_type_ids']);
                }
            }

            if (isset($data['organization'])) {
                $org = $opportunity->organization ?? Organization::query()->create(['name' => $data['organization']['name'] ?? $opportunity->name, 'representative_user_id' => $request->user()->id]);
                $org->fill($data['organization'])->save();
                $opportunity->organization_id = $org->id;
                if ($request->user()->organization_id === null) {
                    $request->user()->forceFill(['organization_id' => $org->id])->save();
                }
            }

            if (isset($data['impact'])) {
                $impact = $data['impact'];
                $opportunity->fill(collect($impact)->except(['beneficiary_group_ids', 'sdg_ids'])->all());
                if (array_key_exists('beneficiary_group_ids', $impact)) {
                    $opportunity->beneficiaryGroups()->sync((array) $impact['beneficiary_group_ids']);
                }
                if (array_key_exists('sdg_ids', $impact)) {
                    $opportunity->sdgs()->sync((array) $impact['sdg_ids']);
                }
            }

            if (isset($data['financials'])) {
                $opportunity->fill($data['financials']);
            }

            if (isset($data['disclosures'])) {
                $disclosures = $data['disclosures'];
                $opportunity->fill(collect($disclosures)->except(['data_accuracy_declared'])->all());
                if (! empty($disclosures['data_accuracy_declared'])) {
                    $opportunity->data_accuracy_declared_at = now();
                }
            }

            $opportunity->save();
            $this->audit->log('opportunity.updated', $opportunity, array_intersect_key($before, $opportunity->getChanges()), $opportunity->getChanges(), $request->user());
        });

        return $this->ok((new OpportunityFullResource($opportunity->refresh()))->resolve($request));
    }

    /** GET /entrepreneur/opportunities/{uuid}/checklist — ENT-02 */
    public function checklist(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('view', $opportunity);

        return $this->ok($this->buildChecklist($opportunity));
    }

    /** POST /entrepreneur/opportunities/{uuid}/submit — ENT-03 */
    public function submit(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('submit', $opportunity);

        $checklist = $this->buildChecklist($opportunity);
        if (! $checklist['complete']) {
            throw new DomainException(__('messages.opportunity.checklist_incomplete'), 'checklist_incomplete', 422, ['checklist' => array_map(fn (array $m): string => $m['label'], $checklist['missing'])]);
        }

        $target = $opportunity->status === OpportunityStatus::NeedsCompletion ? OpportunityStatus::UnderReview : OpportunityStatus::New;
        $opportunity = $this->stateMachine->transition($opportunity, $target, $request->user(), __('messages.opportunity.submitted_by_owner'));

        return $this->ok([
            'reference_no' => $opportunity->reference_no,
            'status' => $opportunity->status->value,
            'status_label' => $opportunity->status->label(),
            'review_due_at' => $opportunity->review_due_at?->toIso8601String(),
        ]);
    }

    /** POST /entrepreneur/opportunities/{uuid}/withdraw — ENT-07 */
    public function withdraw(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('withdraw', $opportunity);
        $opportunity = $this->stateMachine->transition($opportunity, OpportunityStatus::Withdrawn, $request->user(), (string) $request->input('reason', __('messages.opportunity.withdrawn_by_owner')));

        return $this->ok(['status' => $opportunity->status->value, 'status_label' => $opportunity->status->label()]);
    }

    /** GET /entrepreneur/opportunities/{uuid}/indicators */
    public function indicators(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('view', $opportunity);

        return response()->json(['data' => $opportunity->indicators()->with('sdg')->get()->map(fn (ImpactIndicator $i): array => $this->indicatorArray($i))->all()]);
    }

    /** POST /entrepreneur/opportunities/{uuid}/indicators */
    public function storeIndicator(StoreIndicatorRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('manageIndicators', $opportunity);
        $data = $request->validated();

        $indicator = DB::transaction(function () use ($opportunity, $data): ImpactIndicator {
            if (! empty($data['is_primary'])) {
                $opportunity->indicators()->update(['is_primary' => false]);
            }

            return $opportunity->indicators()->create($data);
        });

        $this->audit->log('indicator.created', $indicator, null, ['name' => $indicator->name], $request->user());

        return response()->json(['data' => $this->indicatorArray($indicator)], 201);
    }

    /** DELETE /entrepreneur/opportunities/{uuid}/indicators/{indicator} */
    public function destroyIndicator(Request $request, Opportunity $opportunity, ImpactIndicator $indicator): JsonResponse
    {
        $this->authorize('manageIndicators', $opportunity);
        if ((int) $indicator->opportunity_id !== (int) $opportunity->id) {
            throw new DomainException(__('messages.errors.not_found'), 'not_found', 404);
        }
        $indicator->delete();
        $this->audit->log('indicator.deleted', $indicator, ['name' => $indicator->name], null, $request->user());

        return $this->message(__('messages.deleted'));
    }

    /** POST /entrepreneur/opportunities/{uuid}/documents — DOC-01 */
    public function uploadDocument(UploadDocumentRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('uploadDocument', $opportunity);

        $document = $this->documents->upload(
            $request->file('file'),
            $request->user(),
            $opportunity,
            DocumentKind::from($request->validated('kind')),
            $request->validated('title'),
        );

        return response()->json(['data' => (new DocumentResource($document))->resolve($request), 'message' => __('messages.documents.uploaded')], 201);
    }

    /** GET /entrepreneur/opportunities/{uuid}/preview — ENT-09: exactly what investors see. */
    public function preview(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('view', $opportunity);

        return $this->ok([
            'card' => (new OpportunityCardResource($opportunity))->resolve($request),
            'detail_before_grant' => (new OpportunityDetailResource($opportunity, InvestorAccessLevel::ApprovedMember))->resolve($request),
            'detail_after_grant' => (new OpportunityDetailResource($opportunity, InvestorAccessLevel::Granted))->resolve($request),
        ]);
    }

    /** POST /entrepreneur/opportunities/{uuid}/teaser/approve — MAT-05 owner approval. */
    public function approveTeaser(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('approveTeaserAsOwner', $opportunity);
        $teaser = $this->teasers->approveByOwner($opportunity, $request->user());

        return $this->ok(['version' => $teaser->version, 'approved_by_owner_at' => $teaser->approved_by_owner_at?->toIso8601String(), 'approved_by_council_at' => $teaser->approved_by_council_at?->toIso8601String()]);
    }

    /** @return array{complete: bool, percent: int, missing: array<int, array{key: string, section: string, label: string}>} */
    private function buildChecklist(Opportunity $opportunity): array
    {
        $missing = [];
        foreach (Opportunity::REQUIRED_FOR_SUBMIT as $field) {
            $value = $opportunity->getAttribute($field);
            if ($value === null || $value === '' || $value === []) {
                $missing[] = ['key' => $field, 'section' => $this->sectionOf($field), 'label' => __("validation.attributes.{$field}")];
            }
        }
        foreach (Opportunity::REQUIRED_RELATIONS_FOR_SUBMIT as $relation) {
            if (! $opportunity->{$relation}()->exists()) {
                $missing[] = ['key' => $relation, 'section' => 'basics', 'label' => __("validation.attributes.{$relation}")];
            }
        }
        foreach (Opportunity::REQUIRED_DOCUMENT_KINDS as $kind) {
            if (! $opportunity->documents()->where('kind', $kind)->where('status', '!=', 'rejected')->exists()) {
                $missing[] = ['key' => 'document:'.$kind, 'section' => 'documents', 'label' => DocumentKind::from($kind)->label()];
            }
        }
        if (! $opportunity->indicators()->where('is_primary', true)->exists()) {
            $missing[] = ['key' => 'primary_indicator', 'section' => 'impact', 'label' => __('validation.attributes.primary_indicator')];
        }

        $total = count(Opportunity::REQUIRED_FOR_SUBMIT) + count(Opportunity::REQUIRED_RELATIONS_FOR_SUBMIT) + count(Opportunity::REQUIRED_DOCUMENT_KINDS) + 1;

        return [
            'complete' => $missing === [],
            'percent' => (int) round(($total - count($missing)) / $total * 100),
            'missing' => $missing,
            'required_now' => $opportunity->completionRequests()->open()->get()->map(fn ($c): array => ['id' => $c->id, 'items' => $c->items, 'due_at' => $c->due_at?->toIso8601String()])->all(),
        ];
    }

    private function sectionOf(string $field): string
    {
        foreach (Opportunity::SECTIONS as $section => $fields) {
            if (in_array($field, $fields, true)) {
                return $section;
            }
        }

        return 'basics';
    }

    /** @return array<string, mixed> */
    private function indicatorArray(ImpactIndicator $i): array
    {
        return [
            'id' => $i->id, 'name' => $i->name, 'definition' => $i->definition, 'unit' => $i->unit,
            'baseline' => $i->baseline !== null ? (float) $i->baseline : null, 'target' => $i->target !== null ? (float) $i->target : null,
            'data_source' => $i->data_source, 'frequency' => $i->frequency?->value, 'verification_method' => $i->verification_method,
            'sdg_id' => $i->sdg_id, 'is_primary' => $i->is_primary, 'is_disclosed' => $i->is_disclosed,
        ];
    }
}
