<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Investor\NdaAcceptRequest;
use App\Models\FileAccessGrant;
use App\Models\Opportunity;
use App\Services\InterestService;
use App\Services\NdaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MSG-02 / ENT-06 — the investor reads the full confidentiality undertaking before accepting it.
 * The text comes from the CMS page `nda` and the version from `legal.nda_version` (NdaService).
 */
class NdaController extends Controller
{
    public function __construct(private readonly NdaService $nda, private readonly InterestService $interests) {}

    /** GET /opportunities/{uuid}/nda?locale=ar|en → current NDA text + version + this investor's acceptance state. */
    public function show(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('interact', $opportunity);
        $locale = in_array($request->query('locale'), ['ar', 'en'], true) ? (string) $request->query('locale') : null;

        $grant = $this->grant($opportunity, $request);
        $consent = $grant?->ndaConsent;

        return $this->ok($this->nda->current($locale) + [
            'can_accept' => $grant !== null && $grant->granted_by_owner_at !== null && $grant->nda_consent_id === null,
            'accepted_at' => $consent?->accepted_at?->toIso8601String(),
            'accepted_version' => $consent?->version,
        ]);
    }

    /** POST /opportunities/{uuid}/nda-accept {version, locale?} — ENT-06 */
    public function accept(NdaAcceptRequest $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorize('interact', $opportunity);
        $grant = $this->interests->acceptNda(
            $opportunity,
            $request->user(),
            (string) $request->validated('version'),
            (string) $request->ip(),
            $request->userAgent(),
            $request->validated('locale'),
        );

        return $this->ok([
            'granted' => $grant->isActive(),
            'granted_at' => $grant->granted_by_owner_at?->toIso8601String(),
            'nda_consent_id' => $grant->nda_consent_id,
            'nda_accepted_at' => $grant->ndaConsent?->accepted_at?->toIso8601String(),
            'nda_version' => $grant->ndaConsent?->version,
        ]);
    }

    private function grant(Opportunity $opportunity, Request $request): ?FileAccessGrant
    {
        return FileAccessGrant::query()
            ->with('ndaConsent')
            ->where('opportunity_id', $opportunity->id)
            ->where('investor_user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }
}
