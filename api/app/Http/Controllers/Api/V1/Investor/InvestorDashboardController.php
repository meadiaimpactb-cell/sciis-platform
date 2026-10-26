<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Enums\InfoRequestStatus;
use App\Enums\InterestStatus;
use App\Enums\MeetingStatus;
use App\Http\Controllers\Api\V1\Meetings\MeetingActionController;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Http\Resources\OpportunityCardResource;
use App\Models\InfoRequest;
use App\Models\Interest;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Services\InvestorProfileService;
use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** INV-03 — investor dashboard: completion, counters, open requests, rule-based suggestions, updates, next meeting. */
class InvestorDashboardController extends Controller
{
    /** notification `type` prefix → portal page */
    private const UPDATE_LINKS = [
        'info_request' => '/app/investor/info-requests',
        'nomination' => '/app/investor/nominations',
        'interest' => '/app/investor/interests',
        'meeting' => '/app/investor/meetings',
        'membership' => '/app/investor/dashboard',
        'payment' => '/app/investor/dashboard',
    ];

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

        $updates = $user->notifications()->latest()->limit(10)->get()->map(function ($n): array {
            $data = (array) $n->data;
            $prefix = explode('.', (string) ($data['type'] ?? ''), 2)[0];

            return [
                'id' => $n->id,
                'title' => (string) ($data['title'] ?? ''),
                'body' => (string) ($data['body'] ?? ''),
                'href' => self::UPDATE_LINKS[$prefix] ?? null,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ] + $data;
        })->all();

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
            'completion' => [
                'percent' => $user->investorProfile?->completion_percent ?? 0,
                'missing_items' => $user->investorProfile ? InvestorProfileService::missingItems($user->investorProfile) : [],
            ],
            'requests' => $this->requests($user),
            'suggested' => $suggested,
            'updates' => $updates,
            'next_meeting' => $nextMeeting ? (new MeetingResource($nextMeeting))->resolve($request) : null,
            'access_policy' => setting('opportunity.access_policy', 'nomination_only'),
            'has_connected' => $user->interests()->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value])->exists(),
            // MTG-02 — open meeting follow-up actions assigned to the investor.
            'tasks' => MeetingAction::query()->with(['owner', 'meeting.opportunity'])->where('owner_user_id', $user->id)->whereNull('done_at')->orderBy('due_at')->limit(20)->get()
                ->map(fn (MeetingAction $a): array => MeetingActionController::payload($a, $user->id))->values()->all(),
        ]);
    }

    /**
     * Open interests, pending nominations and info requests in one list (newest first).
     *
     * @return list<array<string, mixed>>
     */
    private function requests($user): array
    {
        $ref = fn (?Opportunity $o): array => ['uuid' => $o?->uuid, 'name' => $o?->name, 'reference_no' => $o?->reference_no];

        $interests = $user->interests()->open()->with('opportunity')->latest('updated_at')->limit(20)->get()
            ->map(fn (Interest $i): array => [
                'kind' => 'interest',
                'id' => $i->id,
                'opportunity' => $ref($i->opportunity),
                'status' => $i->status->value,
                'next_step' => $i->status->label(),
                'next_step_key' => 'interest.'.$i->status->value,
                'updated_at' => ($i->updated_at ?? $i->created_at)?->toIso8601String(),
            ]);

        $nominations = $user->nominations()->whereNull('response')->with('opportunity')->latest('sent_at')->limit(20)->get()
            ->map(fn (Nomination $n): array => [
                'kind' => 'nomination',
                'id' => $n->id,
                'opportunity' => $ref($n->opportunity),
                'status' => 'pending',
                'next_step' => __('notifications.nomination.title'),
                'next_step_key' => 'nomination.pending',
                'updated_at' => ($n->sent_at ?? $n->created_at)?->toIso8601String(),
            ]);

        $info = $user->infoRequests()->where('status', '!=', InfoRequestStatus::Closed->value)->with('opportunity')->latest('updated_at')->limit(20)->get()
            ->map(fn (InfoRequest $r): array => [
                'kind' => 'info_request',
                'id' => $r->id,
                'opportunity' => $ref($r->opportunity),
                'status' => $r->status->value,
                'next_step' => $r->status->label(),
                'next_step_key' => 'info_request.'.$r->status->value,
                'updated_at' => ($r->updated_at ?? $r->created_at)?->toIso8601String(),
            ]);

        return $interests->concat($nominations)->concat($info)
            ->sortByDesc('updated_at')
            ->values()
            ->all();
    }
}
