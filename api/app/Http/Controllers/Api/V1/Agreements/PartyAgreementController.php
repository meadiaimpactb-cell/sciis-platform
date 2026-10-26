<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agreements;

use App\Enums\AgreementParty;
use App\Enums\AgreementStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agreements\SignAgreementRequest;
use App\Http\Resources\AgreementResource;
use App\Models\Agreement;
use App\Services\Agreements\AgreementService;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AGR-04/05 — the parties' side, shared by the owner portal (/entrepreneur/agreements) and the investor portal
 * (/investor/agreements): read-only list and file of their own agreements (approved versions only), click-to-sign,
 * and the signed copy.
 */
abstract class PartyAgreementController extends Controller
{
    public function __construct(
        private readonly AgreementService $agreements,
        private readonly DocumentService $documents,
        private readonly AuditLogger $audit,
    ) {}

    /** The party this portal acts as. */
    abstract protected function party(): AgreementParty;

    /** GET …/agreements */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Agreement::query()->visibleToParties()->with(['opportunity.owner', 'investor', 'currentVersion', 'template', 'signatures']);
        $this->party() === AgreementParty::Owner
            ? $query->whereHas('opportunity', fn ($o) => $o->where('owner_user_id', $user->id))
            : $query->where('investor_user_id', $user->id);

        $party = $this->party()->value;
        $rows = $query->orderByDesc('updated_at')->get()
            ->map(fn (Agreement $a): array => AgreementResource::listItem($a)->resolve($request) + [
                'my_party' => $party,
                'awaiting_my_signature' => $a->status === AgreementStatus::SentForSignature && ! isset($a->signaturesOn()[$party]),
            ]);

        return $this->ok(['agreements' => $rows->values()->all()]);
    }

    /** GET …/agreements/{uuid} */
    public function show(Request $request, Agreement $agreement): JsonResponse
    {
        $this->authorizeParty($request, $agreement);
        $this->audit->logThrottled('agreement.viewed', $agreement, $request->user(), ['party' => $this->party()->value]);

        return $this->ok(AgreementResource::party($agreement)->resolve($request));
    }

    /** POST …/agreements/{uuid}/sign {consent: true, version, text_hash, locale?} — AGR-05 */
    public function sign(SignAgreementRequest $request, Agreement $agreement): JsonResponse
    {
        $this->authorizeParty($request, $agreement);
        $data = $request->validated();

        $result = $this->agreements->sign(
            $agreement,
            $request->user(),
            (int) $data['version'],
            (string) $data['text_hash'],
            $data['locale'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        $fresh = Agreement::query()->findOrFail($agreement->id);

        return $this->ok(AgreementResource::party($fresh)->resolve($request) + ['redirect_url' => $result['redirect_url']]);
    }

    /** GET …/agreements/{uuid}/signed-copy/download-url — the wet-signed PDF, signed URL, access logged. */
    public function signedCopyUrl(Request $request, Agreement $agreement): JsonResponse
    {
        $this->authorizeParty($request, $agreement);
        $document = $agreement->signedDocument ?? throw DomainException::make('agreements.errors.no_signed_copy', 'agreement_no_signed_copy', 404);

        return $this->ok($this->documents->temporaryUrl($document, $request->user()));
    }

    /** @throws AuthorizationException */
    private function authorizeParty(Request $request, Agreement $agreement): void
    {
        if (! $request->user()->can('viewAsParty', [$agreement, $this->party()])) {
            throw new AuthorizationException(__('agreements.errors.not_party'));
        }
    }
}
