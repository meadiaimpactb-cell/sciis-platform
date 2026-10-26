<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Enums\DocumentKind;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Investor\UploadInvestorDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\InvestorProfile;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;

/**
 * INV-01 — membership documents through the secure pipeline (DocumentService: size/type limits from settings,
 * virus scan, private disk + encryption at rest, signed short-lived URLs, access log):
 *  - individual investor:    CV (`investor_cv`, on the profile → `investor_profiles.cv_document_id`);
 *  - institutional investor: commercial register, authorization letter (→ `organizations.authorization_letter_document_id`)
 *                            and the impact / ESG policy file — on the organization.
 * Re-uploading the same kind creates a new version (DOC-03). Only the owner and membership reviewers can open them
 * (DocumentPolicy). The list comes back in GET /investor/profile → `documents`.
 */
class InvestorDocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents, private readonly AuditLogger $audit) {}

    /** POST /investor/profile/documents (multipart: kind, file, title?) */
    public function store(UploadInvestorDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isInvestor()) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }

        $kind = DocumentKind::from((string) $request->validated('kind'));
        $profile = InvestorProfile::query()->firstOrCreate(['user_id' => $user->id]);
        $parent = $kind === DocumentKind::InvestorCv ? $profile : $user->organization;
        if ($parent === null) {
            $message = __('messages.documents.organization_required');
            throw new DomainException($message, 'organization_required', 422, ['kind' => [$message]]);
        }

        $document = $this->documents->upload($request->file('file'), $user, $parent, $kind, $request->validated('title'));

        if ($kind === DocumentKind::InvestorCv) {
            $profile->cv_document_id = $document->id;
        } elseif ($kind === DocumentKind::AuthorizationLetter) {
            $parent->forceFill(['authorization_letter_document_id' => $document->id])->save();
        }
        // INV-02 — a new membership document counts as a profile update ("آخر تحديث").
        $profile->touch();

        $this->audit->log('investor_profile.document_uploaded', $profile, null, ['kind' => $kind->value, 'document_uuid' => $document->uuid, 'version' => $document->version], $user);

        return response()->json([
            'data' => (new DocumentResource($document->load(['owner:id,name,type', 'supersedes:id,uuid'])))->resolve($request),
            'message' => __('messages.documents.uploaded'),
        ], 201);
    }
}
