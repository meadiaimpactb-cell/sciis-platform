<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentAccessAction;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\ScanResult;
use App\Enums\Sensitivity;
use App\Exceptions\DocumentDecryptionException;
use App\Exceptions\DomainException;
use App\Jobs\ScanDocumentJob;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\User;
use App\Support\DocumentCipher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * DOC-01..03 — upload to the configured (KSA-resident) disk, sha256 integrity, size/mime limits from settings,
 * async virus scan, signed temporary URLs only, every access logged (INV-04).
 * SEC-01 — files on a non-S3 disk are encrypted at rest (DocumentCipher, `documents.encrypted`); downloads and
 * scans read them through `plaintextChunks()`. `sha256` / `size_bytes` always describe the plaintext.
 */
class DocumentService
{
    private ?DocumentCipher $cipher = null;

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

        // SEC-01 — non-S3 disks (production shared hosting) get application-level encryption; S3 relies on SSE.
        $encrypted = $this->encryptsAtRest($disk);
        $path = $encrypted
            ? $this->putEncrypted($disk, $folder.'/'.$name, (string) $file->getRealPath())
            : Storage::disk($disk)->putFileAs($folder, $file, $name, ['visibility' => 'private']);
        if ($path === false) {
            throw new DomainException(__('messages.documents.upload_failed'), 'upload_failed', 500);
        }

        return DB::transaction(function () use ($file, $owner, $documentable, $kind, $title, $sensitivity, $disk, $path, $hash, $encrypted): Document {
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
                'encrypted' => $encrypted,
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
        // The signed link names the user it was issued to, so the stream re-checks access at download time
        // (ENT-06: a revoked grant stops links already handed out).
        $streamUrl = fn (): string => URL::temporarySignedRoute('v1.documents.stream', $expiresAt, ['document' => $document->uuid, 'u' => $user->id]);

        // INV-08 — a download that gets an investor watermark always goes through the stream route, which stamps it.
        if ($document->encrypted || ! $this->isS3($document->disk) || app(DocumentWatermarker::class)->applies($document, $user)) {
            // Non-S3 disks (and every encrypted file): signed application route that streams — and decrypts — the file.
            $url = $streamUrl();
        } else {
            try {
                $url = Storage::disk($document->disk)->temporaryUrl($document->path, $expiresAt, [
                    'ResponseContentDisposition' => 'attachment; filename="'.addslashes($this->safeFileName($document)).'"',
                ]);
            } catch (\Throwable) {
                $url = $streamUrl();
            }
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

    // ---------------------------------------------------------------- SEC-01 encryption at rest

    public function isS3(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 's3';
    }

    /** New uploads on a non-S3 disk are encrypted unless DOCUMENTS_ENCRYPT_AT_REST=false. */
    public function encryptsAtRest(string $disk): bool
    {
        return (bool) config('sciis.documents.encrypt_at_rest', true) && ! $this->isS3($disk);
    }

    /**
     * The file's plaintext in chunks — decrypted on the fly when it is stored encrypted (flag, or our header with
     * a known key: covers a file swapped by `documents:encrypt-existing` just before its row was flagged).
     *
     * @return \Generator<int, string>
     *
     * @throws DocumentDecryptionException
     */
    public function plaintextChunks(Document $document): \Generator
    {
        $stream = Storage::disk($document->disk)->readStream($document->path);
        if (! is_resource($stream)) {
            throw new DocumentDecryptionException('Document file is missing.');
        }

        try {
            $head = (string) fread($stream, DocumentCipher::HEADER_LENGTH);
            while (strlen($head) < DocumentCipher::HEADER_LENGTH && ! feof($stream)) {
                $more = fread($stream, DocumentCipher::HEADER_LENGTH - strlen($head));
                if ($more === false || $more === '') {
                    break;
                }
                $head .= $more;
            }

            if ($document->encrypted || $this->cipher()->isEncryptedHeader($head)) {
                yield from $this->cipher()->decryptChunks($stream, $head);

                return;
            }

            if ($head !== '') {
                yield $head;
            }
            while (! feof($stream)) {
                $chunk = fread($stream, DocumentCipher::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                yield $chunk;
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function downloadFileName(Document $document): string
    {
        return $this->safeFileName($document);
    }

    /**
     * `documents:encrypt-existing` — encrypts one plaintext file in place: encrypt to a temp file on the same disk,
     * read it back and verify the decrypted sha256 equals the original's, then swap (rename) and flag the row.
     *
     * @return array{status: 'encrypted'|'would_encrypt'|'flagged'|'missing'|'skipped_s3', hash_matches: bool|null}
     */
    public function encryptExistingFile(Document $document, bool $dryRun = false): array
    {
        if ($this->isS3($document->disk)) {
            return ['status' => 'skipped_s3', 'hash_matches' => null];
        }

        $disk = Storage::disk($document->disk);
        if (! $disk->exists($document->path)) {
            return ['status' => 'missing', 'hash_matches' => null];
        }

        $head = $disk->readStream($document->path);
        $header = is_resource($head) ? (string) fread($head, DocumentCipher::HEADER_LENGTH) : '';
        if (is_resource($head)) {
            fclose($head);
        }
        if ($this->cipher()->isEncryptedHeader($header)) {
            // Already encrypted (e.g. an interrupted earlier run): only the flag is missing.
            if (! $dryRun) {
                DB::table('documents')->where('id', $document->id)->update(['encrypted' => true]);
            }

            return ['status' => 'flagged', 'hash_matches' => null];
        }

        if ($dryRun) {
            return ['status' => 'would_encrypt', 'hash_matches' => null];
        }

        $tmpPath = $document->path.'.enc-'.Str::lower(Str::random(10));
        $local = tmpfile();
        $in = $disk->readStream($document->path);
        if (! is_resource($local) || ! is_resource($in)) {
            throw new \RuntimeException("Cannot open {$document->uuid} for encryption.");
        }

        try {
            $plain = $this->cipher()->encryptStream($in, $local);
            fclose($in);
            rewind($local);
            if (! $disk->writeStream($tmpPath, $local, ['visibility' => 'private'])) {
                throw new \RuntimeException("Cannot write the encrypted copy of {$document->uuid}.");
            }

            // Verify the copy on disk before touching the original.
            $check = $disk->readStream($tmpPath);
            $hash = hash_init('sha256');
            foreach ($this->cipher()->decryptChunks($check) as $chunk) {
                hash_update($hash, $chunk);
            }
            if (is_resource($check)) {
                fclose($check);
            }
            if (! hash_equals($plain['sha256'], hash_final($hash))) {
                throw new \RuntimeException("Verification failed for {$document->uuid}; original left untouched.");
            }

            if (! $disk->move($tmpPath, $document->path)) {
                throw new \RuntimeException("Cannot replace {$document->uuid} with its encrypted copy; original left untouched.");
            }
            DB::table('documents')->where('id', $document->id)->update(['encrypted' => true]);
        } catch (\Throwable $e) {
            if ($disk->exists($tmpPath)) {
                $disk->delete($tmpPath);
            }
            throw $e;
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($local)) {
                fclose($local);
            }
        }

        $matches = hash_equals((string) $document->sha256, $plain['sha256']);
        $this->audit->log('document.encrypted_at_rest', $document, ['encrypted' => false], ['encrypted' => true, 'sha256_matches' => $matches]);

        return ['status' => 'encrypted', 'hash_matches' => $matches];
    }

    /** Encrypts a local file into `$path` on `$disk` (streamed through a temp file). Returns the path or false. */
    private function putEncrypted(string $disk, string $path, string $source): string|false
    {
        $in = fopen($source, 'rb');
        $tmp = tmpfile();
        if ($in === false || $tmp === false) {
            return false;
        }

        try {
            $this->cipher()->encryptStream($in, $tmp);
            rewind($tmp);

            return Storage::disk($disk)->writeStream($path, $tmp, ['visibility' => 'private']) ? $path : false;
        } finally {
            fclose($in);
            fclose($tmp);
        }
    }

    private function cipher(): DocumentCipher
    {
        return $this->cipher ??= DocumentCipher::fromConfig();
    }

    private function safeFileName(Document $document): string
    {
        $ext = pathinfo($document->path, PATHINFO_EXTENSION);
        $base = Str::slug((string) ($document->title ?: $document->kind->value), '_') ?: $document->kind->value;

        return $ext ? "{$base}.{$ext}" : $base;
    }
}
