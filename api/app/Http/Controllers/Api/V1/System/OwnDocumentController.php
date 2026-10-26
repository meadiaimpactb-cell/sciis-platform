<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Opportunity;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DOC-02 / DOC-03 — GET /me/documents/{uuid}/download-url: the uploader, or the owner of the opportunity the file
 * belongs to, opens any version of their own documents (investor membership file, opportunity file).
 * Short-lived signed URL; the access is logged like every other download.
 */
class OwnDocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function __invoke(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();
        $parent = $document->documentable;
        $own = $document->owner_user_id === $user->id || ($parent instanceof Opportunity && $parent->isOwnedBy($user));

        if (! $own) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }

        return $this->ok($this->documents->temporaryUrl($document, $user));
    }
}
