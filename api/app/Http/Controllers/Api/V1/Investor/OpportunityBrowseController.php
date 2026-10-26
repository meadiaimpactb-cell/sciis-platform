<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpportunityCardResource;
use App\Http\Resources\OpportunityDetailResource;
use App\Models\Opportunity;
use App\Models\SavedOpportunity;
use App\Services\AuditLogger;
use App\Support\OpportunityFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OPP-01..04 / OPP-06 / OPP-10 — browse (filtered by access policy), detail at caller's access level, save/unsave.
 * Visibility is enforced by `Opportunity::visibleTo()` + OpportunityPolicy::view (ACC-04).
 */
class OpportunityBrowseController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /opportunities — cards.
     * Filters (App\Support\OpportunityFilters): `filter[sector|region|stage|type|range|beneficiary|sdg]` (taxonomy key
     * or id, comma list = any of; stage = enum), `filter[nominated]=1`; `sort` newest|impact|need; `search`.
     * `meta.counts.{facet}[key]` (OPP-10) reflect the applied filters: each facet is counted with every other filter
     * applied (its own selection excluded), over what the caller may see.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = OpportunityFilters::normalize(array_merge((array) $request->input('filter', []), ['search' => $request->input('search')]));
        $visible = Opportunity::query()->visibleTo($user);

        $query = OpportunityFilters::apply((clone $visible), $filters, $user)
            ->with(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'organization', 'teaser', 'fundingRange']);

        match ((string) $request->input('sort', 'newest')) {
            'impact' => $query->orderByDesc('beneficiaries_target'),
            'need' => $query->orderByDesc('funding_amount'),
            default => $query->orderByDesc('submitted_at'),
        };

        $paginator = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => OpportunityCardResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'access_policy' => setting('opportunity.access_policy'),
                'counts' => OpportunityFilters::facetCounts($visible, $filters, $user),
            ],
        ]);
    }

    /** GET /opportunities/{uuid} — detail at caller's access level (OPP-02/03). */
    public function show(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('view', $opportunity);
        $level = $opportunity->accessLevelFor($request->user());

        $this->audit->log('opportunity.viewed', $opportunity, null, ['access_level' => $level->value], $request->user());

        return $this->ok((new OpportunityDetailResource($opportunity, $level))->resolve($request));
    }

    /** POST /opportunities/{uuid}/save — OPP-04 */
    public function save(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('interact', $opportunity);
        SavedOpportunity::query()->firstOrCreate(['user_id' => $request->user()->id, 'opportunity_id' => $opportunity->id]);

        return $this->message(__('messages.saved'), 201);
    }

    /** DELETE /opportunities/{uuid}/save */
    public function unsave(Request $request, Opportunity $opportunity): JsonResponse
    {
        SavedOpportunity::query()->where('user_id', $request->user()->id)->where('opportunity_id', $opportunity->id)->delete();

        return $this->message(__('messages.deleted'));
    }

    /** GET /investor/saved */
    public function saved(Request $request): JsonResponse
    {
        $ids = $request->user()->savedOpportunities()->pluck('opportunity_id');
        $paginator = Opportunity::query()->visibleTo($request->user())->whereIn('id', $ids)
            ->with(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'organization', 'teaser', 'fundingRange'])
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => OpportunityCardResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }
}
