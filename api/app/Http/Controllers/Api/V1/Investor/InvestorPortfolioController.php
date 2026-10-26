<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Enums\InterestStatus;
use App\Enums\OpportunityStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\FollowUpPresenter;
use App\Services\FollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * INV-06 — "my linked projects": every project the investor is connected to (interest connected / meeting, project
 * connected or later) with its execution status, approved milestones, accepted reports, verified impact values and
 * the owner's published updates. Nothing unverified or internal reaches the investor (IMP-02).
 */
class InvestorPortfolioController extends Controller
{
    public function __construct(
        private readonly FollowUpService $followUps,
        private readonly FollowUpPresenter $presenter,
        private readonly DocumentService $documents,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /investor/portfolio */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = Opportunity::query()
            ->whereIn('status', array_map(fn (OpportunityStatus $s): string => $s->value, OpportunityStatus::connectedOrLater()))
            ->whereHas('interests', fn ($q) => $q->where('investor_user_id', $user->id)
                ->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value]))
            ->latest('updated_at')
            ->get()
            ->map(function (Opportunity $o): array {
                $followUp = $this->followUpOf($o);

                return $this->presenter->summary(FollowUpPresenter::load($o), $followUp, FollowUpPresenter::INVESTOR);
            });

        return response()->json(['data' => $rows->values()->all()]);
    }

    /** GET /investor/portfolio/{uuid} */
    public function show(Request $request, Opportunity $opportunity): JsonResponse
    {
        if (! $this->followUps->investorMayView($opportunity, $request->user())) {
            throw new DomainException(__('messages.errors.not_found'), 'not_found', 404);
        }

        $followUp = $this->followUpOf($opportunity);
        $this->audit->logThrottled('portfolio.viewed', $opportunity, $request->user());

        return $this->ok($this->presenter->detail(FollowUpPresenter::load($opportunity->refresh()), $followUp, FollowUpPresenter::INVESTOR));
    }

    /** GET /investor/portfolio/documents/{uuid}/download-url — attachments of accepted reports and published updates. */
    public function documentUrl(Request $request, Document $document): JsonResponse
    {
        if (! $this->followUps->documentVisibleTo($document, $request->user())) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }

        return $this->ok($this->documents->temporaryUrl($document, $request->user()));
    }

    private function followUpOf(Opportunity $opportunity): ?FollowUp
    {
        return $this->followUps->inFollowUp($opportunity)
            ? $this->followUps->refreshIfStale($opportunity)
            : null;
    }
}
