<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Investor\CompareOpportunitiesRequest;
use App\Http\Resources\OpportunityCompareResource;
use App\Models\Opportunity;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * OPP-12 — side-by-side comparison of 2–4 opportunities the investor may see, public fields only (the same as the
 * card + the approved-member part of the detail view; nothing confidential, whatever the caller's grants).
 */
class OpportunityCompareController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /opportunities/compare?ids=uuid1,uuid2[,uuid3,uuid4] */
    public function __invoke(CompareOpportunitiesRequest $request): JsonResponse
    {
        $user = $request->user();
        $uuids = $request->validated('ids');

        $found = Opportunity::query()->visibleTo($user)->whereIn('uuid', $uuids)
            ->with(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'sdgs', 'organization', 'teaser', 'fundingRange', 'primaryIndicator'])
            ->get()->keyBy('uuid');
        if ($found->count() !== count($uuids)) {
            throw new DomainException(__('messages.discovery.compare_not_visible'), 'compare_not_visible', 404);
        }

        $ordered = collect($uuids)->map(fn (string $uuid): Opportunity => $found[$uuid]);
        $this->audit->log('opportunity.compared', null, null, ['opportunities' => $uuids], $user);

        return $this->ok(['items' => OpportunityCompareResource::collection($ordered)->resolve($request)]);
    }
}
