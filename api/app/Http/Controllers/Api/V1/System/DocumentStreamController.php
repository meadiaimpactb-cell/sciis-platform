<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\DocumentWatermarker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DOC-02 fallback for non-S3 disks: streams a document through a *signed* temporary route.
 * The signature (and its expiry) is validated by the `signed` middleware; the URL names the user it was issued to
 * (DocumentService::temporaryUrl, logged there) and access is checked again for that user before streaming.
 * SEC-01 — encrypted files are decrypted chunk by chunk while streaming (never fully in memory).
 */
class DocumentStreamController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function __invoke(Request $request, Document $document): StreamedResponse
    {
        abort_unless($document->isDownloadable(), 404);
        // ENT-06 — access is checked again for the user the link was issued to (a grant may have been revoked since).
        $user = User::query()->find($request->integer('u'));
        abort_unless($user !== null && $user->isActive() && Gate::forUser($user)->allows('download', $document), 403);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        // INV-08 — investor downloads carry a visible watermark (stamped after decryption) while FEATURE_WATERMARK is on.
        $watermarker = app(DocumentWatermarker::class);
        if ($watermarker->applies($document, $user)) {
            return $watermarker->download($document, $user, $this->documents);
        }

        // Decrypt the header + first record before any byte is sent, so a bad key / damaged file is a clean error.
        $chunks = $this->documents->plaintextChunks($document);
        try {
            $chunks->current();
        } catch (\Throwable $e) {
            Log::error('Document could not be read', ['document' => $document->uuid, 'error' => $e->getMessage()]);
            abort(500, __('messages.documents.unreadable'));
        }

        return response()->streamDownload(function () use ($chunks, $document): void {
            try {
                while ($chunks->valid()) {
                    echo $chunks->current();
                    flush();
                    $chunks->next();
                }
            } catch (\Throwable $e) {
                Log::error('Document stream interrupted', ['document' => $document->uuid, 'error' => $e->getMessage()]);
            }
        }, $this->documents->downloadFileName($document), [
            'Content-Type' => $document->mime,
            'Content-Length' => (string) $document->size_bytes,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
