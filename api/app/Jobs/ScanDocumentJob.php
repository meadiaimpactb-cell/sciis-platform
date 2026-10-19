<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ScanResult;
use App\Models\Document;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DOC-01 — antivirus scan. Driver `clamav` streams the file to clamd (INSTREAM); driver `none` marks clean.
 */
class ScanDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $documentId) {}

    public function handle(AuditLogger $audit): void
    {
        $document = Document::query()->find($this->documentId);
        if ($document === null) {
            return;
        }

        $driver = (string) config('sciis.documents.scan_driver', 'none');
        $result = $driver === 'clamav' ? $this->scanWithClamAv($document) : ScanResult::Clean;

        $document->scan_result = $result;
        $document->scanned_at = now();
        $document->save();

        $audit->log('document.scanned', $document, null, ['result' => $result->value, 'driver' => $driver]);

        if ($result === ScanResult::Infected) {
            Log::warning('Infected document detected', ['document' => $document->uuid]);
        }
    }

    private function scanWithClamAv(Document $document): ScanResult
    {
        $host = (string) config('services.clamav.host', '127.0.0.1');
        $port = (int) config('services.clamav.port', 3310);

        try {
            $stream = Storage::disk($document->disk)->readStream($document->path);
            if ($stream === null) {
                return ScanResult::Pending;
            }

            $socket = @fsockopen($host, $port, $errno, $errstr, 10);
            if ($socket === false) {
                Log::error('clamd unreachable', ['error' => $errstr]);

                return ScanResult::Pending;
            }

            fwrite($socket, "zINSTREAM\0");
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));
            $response = (string) fgets($socket);
            fclose($socket);
            fclose($stream);

            if (str_contains($response, 'FOUND')) {
                return ScanResult::Infected;
            }

            return str_contains($response, 'OK') ? ScanResult::Clean : ScanResult::Pending;
        } catch (\Throwable $e) {
            Log::error('ClamAV scan failed', ['error' => $e->getMessage()]);

            return ScanResult::Pending;
        }
    }
}
