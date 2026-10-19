<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentAccessAction;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\ScanResult;
use App\Enums\Sensitivity;
use App\Exceptions\DomainException;
use App\Jobs\ScanDocumentJob;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * DOC-01..03 — upload to the configured (KSA-resident) disk, sha256 integrity, size/mime limits from settings,
 * async virus scan, signed temporary URLs only, every access logged (INV-04).
 */
class DocumentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
    ) {}

    public function disk(): string
    {
        return (string) config('sciis.documents.disk', config('filesystems.documents_disk', 'local'));
    }

    public function upload(UploadedFile $file, User $owner, Model $documentable, DocumentKind $kind, ?string $title = null, Sensitivity $sensitivity = Sensitivity::Confidential): Document
    {
        $this->validateFile($file);

        $disk = $this->disk();
        $hash = hash_file('sha256', $file->getRealPath()) ?: '';
        $folder = sprintf('%s/%d/%s', Str::snake(class_basename($documentable)), $documentable->getKey(), now()->format('Y/m'));
        $name = sprintf('%s_%s.%s', $kind->value, Str::uuid(), $file->getClientOriginalExtension() ?: 'bin');

        $path = Storage::disk($disk)->putFileAs($folder, $file, $name, ['visibility' => 'private']);
        if ($path === false) {
            throw new DomainException(__('messages.documents.upload_failed'), 'upload_failed', 500);
        }

        return DB::transaction(function () use ($file, $owner, $documentable, $kind, $title, $sensitivity, $disk, $path, $hash): Document {
            $previous = Document::query()
                ->where('documentable_type', $documentable->getMorphClass())
                ->where('documentable_id', $documentable->getKey())
                ->where('kind', $kind->value)
                ->orderByDesc('version')
                ->first();

            $document = Document::query()->create([
                'owner_user_id' => $owner->id,
                'documentable_type' => $documentable->getMorphClass(),
                'documentable_id' => $documentable->getKey(),
                'kind' => $kind,
                'title' => $title ?: $file->getClientOriginalName(),
                'disk' => $disk,
                'path' => $path,
                'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
                'size_bytes' => $file->getSize() ?: 0,
                'sha256' => $hash,
                'sensitivity' => $sensitivity,
                'status' => DocumentStatus::PendingReview,
                'version' => $previous ? $previous->version + 1 : 1,
                'supersedes_document_id' => $previous?->id,
                'scan_result' => ScanResult::Pending,
            ]);

            $this->audit->log('document.uploaded', $document, null, ['kind' => $kind->value, 'sha256' => $hash, 'size' => $document->size_bytes], $owner);

            ScanDocumentJob::dispatch($document->id)->onQueue('documents');

            return $document;
        });
    }

    public function validateFile(UploadedFile $file): void
    {
        $max = $this->settings->maxDocumentSizeBytes();
        if (($file->getSize() ?: 0) > $max) {
            throw new DomainException(__('messages.documents.too_large', ['max' => (int) ($max / 1024 / 1024)]), 'file_too_large', 422, ['file' => [__('messages.documents.too_large', ['max' => (int) ($max / 1024 / 1024)])]]);
        }

        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        if (! in_array($mime, $this->settings->allowedMimes(), true)) {
            throw new DomainException(__('messages.documents.mime_not_allowed', ['mime' => $mime]), 'mime_not_allowed', 422, ['file' => [__('messages.documents.mime_not_allowed', ['mime' => $mime])]]);
        }
    }

    /** DOC-02 — short-lived signed URL + access log. Caller is responsible for authorization (DocumentPolicy). */
    public function temporaryUrl(Document $document, User $user, DocumentAccessAction $action = DocumentAccessAction::Download): array
    {
        if (! $document->isDownloadable()) {
            throw new DomainException(__('messages.documents.not_available'), 'document_not_available', 422);
        }

        $minutes = (int) config('sciis.documents.signed_url_minutes', 10);
        $expiresAt = now()->addMinutes($minutes);
        $disk = Storage::disk($document->disk);

        try {
            $url = $disk->temporaryUrl($document->path, $expiresAt, [
                'ResponseContentDisposition' => 'attachment; filename="'.addslashes($this->safeFileName($document)).'"',
            ]);
        } catch (\Throwable) {
            // Local disk (dev/tests): signed application route that streams the file.
            $url = URL::temporarySignedRoute('v1.documents.stream', $expiresAt, ['document' => $document->uuid]);
        }

        $this->logAccess($document, $user, $action);

        return ['url' => $url, 'expires_at' => $expiresAt->toIso8601String()];
    }

    public function logAccess(Document $document, ?User $user, DocumentAccessAction $action): void
    {
        $request = app()->bound('request') ? request() : null;

        DocumentAccessLog::query()->create([
            'document_id' => $document->id,
            'user_id' => $user?->id,
            'action' => $action,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 1000) : null,
            'created_at' => now(),
        ]);

        $this->audit->log('document.'.$action->value, $document, null, ['document_uuid' => $document->uuid], $user);
    }

    /** ADM — reviewer accepts / rejects / marks a required document. */
    public function review(Document $document, User $reviewer, DocumentStatus $status, ?string $note): Document
    {
        $before = ['status' => $document->status->value];
        $document->status = $status;
        $document->reviewer_note = $note;
        $document->save();

        $this->audit->log('document.reviewed', $document, $before, ['status' => $status->value, 'note' => $note], $reviewer);

        return $document;
    }

    private function safeFileName(Document $document): string
    {
        $ext = pathinfo($document->path, PATHINFO_EXTENSION);
        $base = Str::slug((string) ($document->title ?: $document->kind->value), '_') ?: $document->kind->value;

        return $ext ? "{$base}.{$ext}" : $base;
    }
}
