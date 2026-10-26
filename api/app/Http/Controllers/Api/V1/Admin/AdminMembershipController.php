<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\MembershipStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TransitionMembershipRequest;
use App\Http\Resources\MembershipResource;
use App\Models\Membership;
use App\Models\TaxonomyTerm;
use App\Services\AuditLogger;
use App\Services\MembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADM-06 — membership review & decisions; MAT-01 — the investor browser (multi-criteria filters). */
class AdminMembershipController extends Controller
{
    /** MAT-01 filter → [InvestorProfile relation, taxonomy group]. Values: term key or id, comma list = any of. */
    private const TERM_FILTERS = [
        'sector' => ['sectors', 'sectors'],
        'stage' => ['stages', 'project_stages'],
        'region' => ['regions', 'regions'],
        'type' => ['investmentTypes', 'investment_types'],
    ];

    public function __construct(
        private readonly MembershipService $memberships,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /admin/memberships — filters (`filter[x]` or top-level): status (comma list), search (name/email),
     * investor_type (individual|institutional), sector, stage, region, type (investment type), range (ticket =
     * investment range term). Different criteria combine with AND; several values of one criterion with OR.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Membership::class);

        $filter = fn (string $key): mixed => $request->input('filter.'.$key, $request->input($key));
        $list = fn (mixed $v): array => array_values(array_filter(array_map('trim', is_array($v) ? array_map('strval', $v) : explode(',', (string) $v)), fn (string $x): bool => $x !== ''));

        $status = $list($filter('status'));
        $search = trim((string) $filter('search'));
        $investorType = $this->investorType((string) $filter('investor_type'));

        $query = Membership::query()
            ->with(['user.organization', 'user.investorProfile', 'reviewer', 'approver'])
            ->when($status !== [], fn (Builder $q) => $q->whereIn('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->where(fn (Builder $w) => $w->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.strtolower($search).'%'))))
            ->when($investorType !== null, fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->where('type', $investorType)));

        foreach (self::TERM_FILTERS as $key => [$relation, $group]) {
            $values = $list($filter($key));
            if ($values === []) {
                continue;
            }
            $ids = $this->termIds($group, $values);
            $query->whereHas('user.investorProfile.'.$relation, fn (Builder $t) => $t->whereIn('taxonomy_terms.id', $ids === [] ? [0] : $ids));
        }

        $ranges = $list($filter('range'));
        if ($ranges !== []) {
            $ids = $this->termIds('investment_ranges', $ranges);
            $query->whereHas('user.investorProfile', fn (Builder $p) => $p->whereIn('investment_range_id', $ids === [] ? [0] : $ids));
        }

        $paginator = $query
            ->orderByRaw("case when status = 'under_review' then 0 else 1 end")
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => MembershipResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ]);
    }

    /** GET /admin/memberships/{id} */
    public function show(Request $request, Membership $membership): JsonResponse
    {
        $this->authorize('view', $membership);
        // AUD-01 — staff read access to a membership file (throttled: one row per user per file per 10 minutes).
        $this->audit->logThrottled('membership.viewed', $membership, $request->user(), ['user_id' => $membership->user_id]);

        return $this->ok((new MembershipResource($membership->load(['user.organization', 'user.investorProfile', 'reviewer', 'approver'])))->resolve($request));
    }

    /** POST /admin/memberships/{id}/transition */
    public function transition(TransitionMembershipRequest $request, Membership $membership): JsonResponse
    {
        $this->authorize('review', $membership);
        $membership = $this->memberships->transition($membership, MembershipStatus::from($request->validated('to')), $request->user(), $request->validated('note'));

        return $this->ok((new MembershipResource($membership->load(['user', 'reviewer', 'approver'])))->resolve($request));
    }

    /** `individual` / `institutional` (or the full user type) → UserType value; anything else → no filter. */
    private function investorType(string $value): ?string
    {
        return match ($value) {
            'individual', UserType::InvestorIndividual->value => UserType::InvestorIndividual->value,
            'institutional', UserType::InvestorInstitutional->value => UserType::InvestorInstitutional->value,
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $values  keys or numeric ids
     * @return array<int, int>
     */
    private function termIds(string $group, array $values): array
    {
        $numeric = array_map('intval', array_filter($values, 'ctype_digit'));

        return TaxonomyTerm::query()
            ->where('group', $group)
            ->where(fn (Builder $w) => $w->whereIn('key', $values)->when($numeric !== [], fn (Builder $n) => $n->orWhereIn('id', $numeric)))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
