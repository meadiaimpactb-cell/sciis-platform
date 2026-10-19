<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DOC-02 fallback for non-S3 disks: streams a document through a *signed* temporary route.
 * The signature (and its expiry) is validated by the `signed` middleware; authorization already
 * happened when the URL was issued (DocumentService::temporaryUrl) and was logged.
 */
class DocumentStreamController extends Controller
{
    public function __invoke(Request $request, Document $document): StreamedResponse
    {
        abort_unless($document->isDownloadable(), 404);

        $disk = Storage::disk($document->disk);
        abort_unless($disk->exists($document->path), 404);

        $name = ($document->title ? \Illuminate\Support\Str::slug($document->title, '_') : $document->kind->value).'.'.pathinfo($document->path, PATHINFO_EXTENSION);

        return $disk->download($document->path, $name, ['Content-Type' => $document->mime]);
    }
}
