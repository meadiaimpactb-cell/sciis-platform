<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TransitionMembershipRequest;
use App\Http\Resources\MembershipResource;
use App\Models\Membership;
use App\Services\MembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADM-06 — membership review & decisions. */
class AdminMembershipController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    /** GET /admin/memberships */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Membership::class);

        $paginator = Membership::query()
            ->with(['user.organization', 'user.investorProfile', 'reviewer', 'approver'])
            ->when($request->filled('status'), fn (Builder $q) => $q->whereIn('status', (array) $request->input('status')))
            ->when($request->filled('search'), fn (Builder $q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$request->input('search').'%')->orWhere('email', 'like', '%'.$request->input('search').'%')))
            ->orderByRaw("case when status = 'under_review' then 0 else 1 end")
            ->orderByDesc('submitted_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => MembershipResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /** GET /admin/memberships/{id} */
    public function show(Request $request, Membership $membership): JsonResponse
    {
        $this->authorize('view', $membership);

        return $this->ok((new MembershipResource($membership->load(['user.organization', 'user.investorProfile', 'reviewer', 'approver'])))->resolve($request));
    }

    /** POST /admin/memberships/{id}/transition */
    public function transition(TransitionMembershipRequest $request, Membership $membership): JsonResponse
    {
        $this->authorize('review', $membership);
        $membership = $this->memberships->transition($membership, MembershipStatus::from($request->validated('to')), $request->user(), $request->validated('note'));

        return $this->ok((new MembershipResource($membership->load(['user', 'reviewer', 'approver'])))->resolve($request));
    }
}
