<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentService;
use Illuminate\Console\Command;

/**
 * SEC-01 — encrypts, in place, document files that were stored in plaintext on a non-S3 disk (before encryption at
 * rest existed). Per file: encrypt to a temp file on the same disk, read it back and verify the decrypted sha256,
 * then swap it in (rename) and set `documents.encrypted`. A failure leaves the original untouched; the command is
 * idempotent (already-encrypted files are only flagged) and can be re-run safely.
 *
 *   php artisan documents:encrypt-existing --dry-run   # report only
 *   php artisan documents:encrypt-existing             # apply
 *
 * Take a backup of the documents disk first; keep APP_KEY safe — without it the files cannot be decrypted.
 */
class EncryptExistingDocumentsCommand extends Command
{
    protected $signature = 'documents:encrypt-existing {--dry-run : List the files that would be encrypted without changing anything}';

    protected $description = 'Encrypt plaintext document files on non-S3 disks (application-level encryption at rest)';

    public function handle(DocumentService $documents): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = ['encrypted' => 0, 'would_encrypt' => 0, 'flagged' => 0, 'missing' => 0, 'skipped_s3' => 0, 'failed' => 0];

        Document::withTrashed()
            ->where('encrypted', false)
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($documents, $dry, &$counts): void {
                foreach ($chunk as $document) {
                    try {
                        $result = $documents->encryptExistingFile($document, $dry);
                    } catch (\Throwable $e) {
                        $counts['failed']++;
                        $this->error("#{$document->id} {$document->uuid}: {$e->getMessage()}");

                        continue;
                    }

                    $counts[$result['status']]++;
                    if ($result['status'] === 'missing') {
                        $this->warn("#{$document->id} {$document->uuid}: file missing on disk “{$document->disk}”.");
                    } elseif ($result['status'] === 'would_encrypt') {
                        $this->line("#{$document->id} {$document->uuid}: would encrypt ({$document->size_bytes} bytes).");
                    } elseif ($result['status'] === 'encrypted' && $result['hash_matches'] === false) {
                        $this->warn("#{$document->id} {$document->uuid}: encrypted, but the stored sha256 differs from the file's content.");
                    }
                }
            });

        $this->newLine();
        $this->table(['Result', 'Files'], collect($counts)->map(fn (int $n, string $k): array => [$k, $n])->values()->all());
        if ($dry) {
            $this->info('Dry run — nothing was changed.');
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
