<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Enums\InterestStatus;
use App\Enums\MeetingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Http\Resources\OpportunityCardResource;
use App\Models\Meeting;
use App\Models\Opportunity;
use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** INV-03 — investor dashboard: counters, rule-based suggestions, updates, next meeting. */
class InvestorDashboardController extends Controller
{
    public function __construct(private readonly MatchingService $matching) {}

    /** GET /investor/dashboard */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $counters = [
            'saved' => $user->savedOpportunities()->count(),
            'interests' => $user->interests()->open()->count(),
            'info_requests' => $user->infoRequests()->whereIn('status', ['sent', 'processing'])->count(),
            'meetings' => Meeting::query()->where('status', MeetingStatus::Scheduled->value)->whereHas('participants', fn ($p) => $p->where('user_id', $user->id))->count(),
            'nominations_pending' => $user->nominations()->whereNull('response')->count(),
        ];

        // Suggested (rule-based, transparent): visible opportunities ranked by matched criteria count.
        $profile = $user->investorProfile?->load(['sectors', 'stages', 'investmentTypes', 'regions']);
        $suggested = Opportunity::query()
            ->visibleTo($user)
            ->with(['sector', 'regions', 'investmentTypes', 'beneficiaryGroups', 'organization', 'teaser', 'fundingRange'])
            ->whereDoesntHave('interests', fn ($i) => $i->where('investor_user_id', $user->id)->open())
            ->limit(30)
            ->get()
            ->map(function (Opportunity $o) use ($profile, $request): array {
                $matched = $profile ? $this->matching->matchedCriteria($profile, $o) : [];

                return ['match_score' => count($matched), 'matched_criteria' => $matched] + (new OpportunityCardResource($o))->resolve($request);
            })
            ->sortByDesc('match_score')
            ->take(6)
            ->values()
            ->all();

        $updates = $user->notifications()->latest()->limit(10)->get()->map(fn ($n): array => ['id' => $n->id, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String()] + (array) $n->data)->all();

        $nextMeeting = Meeting::query()
            ->with(['opportunity', 'participants.user', 'organizer', 'actions'])
            ->where('status', MeetingStatus::Scheduled->value)
            ->where('starts_at', '>=', now())
            ->whereHas('participants', fn ($p) => $p->where('user_id', $user->id))
            ->orderBy('starts_at')
            ->first();

        return $this->ok([
            'counters' => $counters,
            'membership_status' => $user->membership?->status->value,
            'completion_percent' => $user->investorProfile?->completion_percent ?? 0,
            'suggested' => $suggested,
            'updates' => $updates,
            'next_meeting' => $nextMeeting ? (new MeetingResource($nextMeeting))->resolve($request) : null,
            'access_policy' => setting('opportunity.access_policy', 'nomination_only'),
            'has_connected' => $user->interests()->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value])->exists(),
        ]);
    }
}
