<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reporting\AdvisoryOpinionRequest;
use App\Http\Requests\Admin\Reporting\AdvisoryReferralRequest;
use App\Models\AdvisoryReferral;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\AdvisoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * EVL-04 — advisory committee referral.
 *  - Evaluation supervisors (`evaluations.assign` or `evaluations.approve`) refer, list every referral and its
 *    opinion, and can end access early.
 *  - The advisory member sees only referrals addressed to them, opens only actively referred files
 *    (OpportunityPolicy) and answers until expiry.
 */
class AdminAdvisoryController extends Controller
{
    public function __construct(private readonly AdvisoryService $advisory) {}

    /** GET /admin/advisory-referrals?status=active|answered|expired */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = (string) $request->input('status', '');

        $list = AdvisoryReferral::query()->with(['opportunity', 'advisor', 'referrer'])
            ->when(! $this->supervises($user), fn (Builder $q) => $q->where('advisory_user_id', $user->id))
            ->when($status === 'active', fn (Builder $q) => $q->active()->whereNull('opinion'))
            ->when($status === 'answered', fn (Builder $q) => $q->whereNotNull('opinion'))
            ->when($status === 'expired', fn (Builder $q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', now()))
            ->latest('id')->limit(200)->get();

        return $this->ok([
            'items' => $list->map(fn (AdvisoryReferral $r): array => $this->advisory->toArray($r))->all(),
            'can_refer' => $this->supervises($user),
        ]);
    }

    /** GET /admin/advisory-referrals/advisors — picker for the referral form. */
    public function advisors(Request $request): JsonResponse
    {
        abort_unless($this->supervises($request->user()), 403, __('messages.errors.forbidden'));

        return $this->ok(['items' => $this->advisory->advisors()->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all()]);
    }

    /** GET /admin/opportunities/{uuid}/advisory-referrals */
    public function forOpportunity(Request $request, Opportunity $opportunity): JsonResponse
    {
        $user = $request->user();
        $supervises = $this->supervises($user);
        $addressed = AdvisoryReferral::query()->where('opportunity_id', $opportunity->id)->where('advisory_user_id', $user->id)->exists();
        abort_unless($supervises || $addressed, 403, __('messages.errors.forbidden'));

        $list = AdvisoryReferral::query()->with(['opportunity', 'advisor', 'referrer'])->where('opportunity_id', $opportunity->id)
            ->when(! $supervises, fn (Builder $q) => $q->where('advisory_user_id', $user->id))
            ->latest('id')->get();

        return $this->ok([
            'items' => $list->map(fn (AdvisoryReferral $r): array => $this->advisory->toArray($r))->all(),
            'can_refer' => $supervises,
        ]);
    }

    /** POST /admin/opportunities/{uuid}/advisory-referrals */
    public function store(AdvisoryReferralRequest $request, Opportunity $opportunity): JsonResponse
    {
        abort_unless($this->supervises($request->user()) && $request->user()->can('view', $opportunity), 403, __('messages.errors.forbidden'));

        $advisor = User::query()->findOrFail((int) $request->validated('advisory_user_id'));
        $referral = $this->advisory->refer($opportunity, $advisor, (string) $request->validated('question'), Carbon::parse($request->validated('expires_at'))->endOfDay(), $request->user());

        return response()->json(['data' => $this->advisory->toArray($referral->load(['opportunity', 'advisor', 'referrer']))], 201);
    }

    /** POST /admin/advisory-referrals/{id}/opinion */
    public function opinion(AdvisoryOpinionRequest $request, AdvisoryReferral $advisoryReferral): JsonResponse
    {
        $referral = $this->advisory->opine($advisoryReferral, $request->user(), (string) $request->validated('opinion'));

        return $this->ok($this->advisory->toArray($referral->load(['opportunity', 'advisor', 'referrer'])));
    }

    /** POST /admin/advisory-referrals/{id}/end — ends the access now. */
    public function end(Request $request, AdvisoryReferral $advisoryReferral): JsonResponse
    {
        abort_unless($this->supervises($request->user()), 403, __('messages.errors.forbidden'));

        return $this->ok($this->advisory->toArray($this->advisory->end($advisoryReferral, $request->user())->load(['opportunity', 'advisor', 'referrer'])));
    }

    private function supervises(User $user): bool
    {
        return $user->can('evaluations.assign') || $user->can('evaluations.approve');
    }
}
