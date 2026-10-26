<?php

declare(strict_types=1);

use App\Enums\DocumentKind;
use App\Exceptions\DocumentDecryptionException;
use App\Models\Document;
use App\Models\Opportunity;
use App\Services\DocumentService;
use App\Support\DocumentCipher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** SEC-01 — application-level encryption at rest for documents on a non-S3 disk (DOCUMENTS_DISK=local). */
beforeEach(function (): void {
    Storage::fake('local');
    $this->owner = $this->entrepreneur();
    $this->opportunity = Opportunity::factory()->create(['owner_user_id' => $this->owner->id]);
    $this->plain = "%PDF-1.4\n% SCIIS confidential financials — رقم سري 123456\n".str_repeat('row,value,42;', 2000);
});

/** Streams a document through the signed route the API hands out and returns the received bytes. */
function downloadThroughSignedUrl(TestCase $test, Document $document): string
{
    $url = app(DocumentService::class)->temporaryUrl($document, $document->owner)['url'];
    $response = $test->get($url)->assertOk();

    return $response->streamedContent();
}

it('stores uploads encrypted on the local disk and streams the plaintext back', function (): void {
    $this->actingAs($this->owner, 'api');
    $file = UploadedFile::fake()->createWithContent('financials.pdf', $this->plain);

    $uuid = $this->post("/api/v1/entrepreneur/opportunities/{$this->opportunity->uuid}/documents", ['kind' => 'financial_statements', 'file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.uuid');

    $document = Document::query()->where('uuid', $uuid)->firstOrFail();
    $stored = Storage::disk('local')->get($document->path);

    expect($document->encrypted)->toBeTrue()
        ->and($stored)->not->toBe($this->plain)
        ->and($stored)->toStartWith(DocumentCipher::MAGIC)
        ->and(str_contains($stored, 'SCIIS confidential'))->toBeFalse()
        ->and(str_contains($stored, '123456'))->toBeFalse()
        ->and($document->sha256)->toBe(hash('sha256', $this->plain))
        ->and($document->size_bytes)->toBe(strlen($this->plain))
        ->and($document->scan_result->value)->toBe('clean');

    $received = downloadThroughSignedUrl($this, $document);
    expect($received)->toBe($this->plain);
});

it('round-trips multi-record files and rejects tampered or truncated ciphertext', function (): void {
    $cipher = DocumentCipher::fromConfig();
    $plain = random_bytes(DocumentCipher::CHUNK_SIZE * 2 + 12345);

    $in = fopen('php://temp', 'w+b');
    fwrite($in, $plain);
    rewind($in);
    $out = fopen('php://temp', 'w+b');
    $meta = $cipher->encryptStream($in, $out);
    rewind($out);
    $encrypted = stream_get_contents($out);

    expect($meta)->toBe(['bytes' => strlen($plain), 'sha256' => hash('sha256', $plain)]);

    $decrypt = function (string $bytes) use ($cipher): string {
        $s = fopen('php://temp', 'w+b');
        fwrite($s, $bytes);
        rewind($s);

        return implode('', iterator_to_array($cipher->decryptChunks($s), false));
    };

    expect($decrypt($encrypted))->toBe($plain);

    // empty file
    $e = fopen('php://temp', 'w+b');
    $o = fopen('php://temp', 'w+b');
    $cipher->encryptStream($e, $o);
    rewind($o);
    expect($decrypt(stream_get_contents($o)))->toBe('');

    $tampered = $encrypted;
    $tampered[DocumentCipher::HEADER_LENGTH + 40] = chr(ord($tampered[DocumentCipher::HEADER_LENGTH + 40]) ^ 0x01);
    expect(fn () => $decrypt($tampered))->toThrow(DocumentDecryptionException::class);

    $truncated = substr($encrypted, 0, strlen($encrypted) - DocumentCipher::CHUNK_SIZE / 2);
    expect(fn () => $decrypt($truncated))->toThrow(DocumentDecryptionException::class);

    // a file encrypted under another APP_KEY is refused (not mistaken for plaintext)
    $other = new DocumentCipher([random_bytes(32)]);
    expect($other->isEncryptedHeader(substr($encrypted, 0, DocumentCipher::HEADER_LENGTH)))->toBeFalse()
        ->and($cipher->isEncryptedHeader(substr($encrypted, 0, DocumentCipher::HEADER_LENGTH)))->toBeTrue();
});

it('still decrypts files written with a previous APP_KEY after a rotation', function (): void {
    $oldKey = config('app.key');
    $old = DocumentCipher::fromConfig();
    $in = fopen('php://temp', 'w+b');
    fwrite($in, 'before rotation');
    rewind($in);
    $out = fopen('php://temp', 'w+b');
    $old->encryptStream($in, $out);
    rewind($out);

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$oldKey]]);
    $rotated = DocumentCipher::fromConfig();

    expect(implode('', iterator_to_array($rotated->decryptChunks($out), false)))->toBe('before rotation');
});

it('keeps serving legacy plaintext files and leaves S3 disks to server-side encryption', function (): void {
    $path = 'opportunity/legacy/old.pdf';
    Storage::disk('local')->put($path, $this->plain);
    $document = Document::factory()->forOpportunity($this->opportunity)->create(['path' => $path, 'size_bytes' => strlen($this->plain), 'sha256' => hash('sha256', $this->plain)]);

    expect($document->refresh()->encrypted)->toBeFalse()
        ->and(downloadThroughSignedUrl($this, $document))->toBe($this->plain);

    $service = app(DocumentService::class);
    config(['filesystems.disks.s3.driver' => 's3']);
    expect($service->encryptsAtRest('local'))->toBeTrue()
        ->and($service->encryptsAtRest('s3'))->toBeFalse();
});

it('encrypts existing plaintext files in place with documents:encrypt-existing (dry run first)', function (): void {
    $path = 'opportunity/legacy/plan.pdf';
    Storage::disk('local')->put($path, $this->plain);
    $document = Document::factory()->forOpportunity($this->opportunity)->create([
        'kind' => DocumentKind::BusinessPlan, 'path' => $path, 'size_bytes' => strlen($this->plain), 'sha256' => hash('sha256', $this->plain),
    ]);
    $missing = Document::factory()->forOpportunity($this->opportunity)->create(['path' => 'opportunity/legacy/gone.pdf']);

    $this->artisan('documents:encrypt-existing', ['--dry-run' => true])->assertSuccessful();
    expect(Storage::disk('local')->get($path))->toBe($this->plain)
        ->and($document->refresh()->encrypted)->toBeFalse();

    $this->artisan('documents:encrypt-existing')->assertSuccessful();

    $stored = Storage::disk('local')->get($path);
    expect($document->refresh()->encrypted)->toBeTrue()
        ->and($missing->refresh()->encrypted)->toBeFalse()
        ->and($stored)->toStartWith(DocumentCipher::MAGIC)
        ->and(str_contains($stored, 'SCIIS confidential'))->toBeFalse()
        ->and(Storage::disk('local')->files('opportunity/legacy'))->toBe(['opportunity/legacy/plan.pdf']) // temp copy cleaned up
        ->and(downloadThroughSignedUrl($this, $document))->toBe($this->plain);

    $this->assertDatabaseHas('audit_logs', ['action' => 'document.encrypted_at_rest', 'auditable_id' => $document->id]);

    // idempotent: a second run leaves the encrypted file as it is
    $this->artisan('documents:encrypt-existing')->assertSuccessful();
    expect(Storage::disk('local')->get($path))->toBe($stored);
});

it('flags a file that was already swapped but not yet marked encrypted', function (): void {
    $path = 'opportunity/legacy/swapped.pdf';
    $in = fopen('php://temp', 'w+b');
    fwrite($in, $this->plain);
    rewind($in);
    $out = fopen('php://temp', 'w+b');
    DocumentCipher::fromConfig()->encryptStream($in, $out);
    rewind($out);
    Storage::disk('local')->put($path, stream_get_contents($out));
    $document = Document::factory()->forOpportunity($this->opportunity)->create(['path' => $path, 'size_bytes' => strlen($this->plain), 'encrypted' => false]);

    // the read path recognises our header even before the flag is set
    expect(downloadThroughSignedUrl($this, $document))->toBe($this->plain);

    $this->artisan('documents:encrypt-existing')->assertSuccessful();
    expect($document->refresh()->encrypted)->toBeTrue()
        ->and(downloadThroughSignedUrl($this, $document))->toBe($this->plain);
});
