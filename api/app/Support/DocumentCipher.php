<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DocumentDecryptionException;
use Generator;
use RuntimeException;

/**
 * SEC-01 / DOC-01 — application-level encryption at rest for document files kept on a non-S3 disk.
 *
 * AES-256-GCM in 1 MiB records, so a file of the configured maximum size (25 MB) is never held in memory at once.
 * The key is derived (HKDF-SHA256) from APP_KEY; keys listed in APP_PREVIOUS_KEYS still decrypt after a rotation.
 *
 *   header = "SCIIDOC1" | key id (8) | file id (16) | record size (uint32 BE)            — 36 bytes
 *   record = flag (1: 0x00 more, 0x01 final) | length (uint32 BE) | nonce (12) | tag (16) | ciphertext
 *
 * Each record's additional data binds the file id, the record index and the final flag, so records cannot be
 * reordered, swapped between files, dropped or truncated without the decryption failing.
 */
final class DocumentCipher
{
    public const MAGIC = 'SCIIDOC1';

    public const HEADER_LENGTH = 36;

    public const CHUNK_SIZE = 1048576;

    private const CIPHER = 'aes-256-gcm';

    private const INFO = 'sciis-documents-at-rest-v1';

    private const MAX_RECORD = 8 * 1048576;

    /** @var array<string, string> key id => derived key */
    private array $keys = [];

    private string $currentId = '';

    /** @param list<string> $rawKeys application keys as raw bytes, the current key first */
    public function __construct(array $rawKeys)
    {
        foreach (array_values(array_filter($rawKeys, fn (string $k): bool => $k !== '')) as $i => $raw) {
            $key = hash_hkdf('sha256', $raw, 32, self::INFO);
            $id = substr(hash('sha256', 'sciis-doc-key-id|'.$key, true), 0, 8);
            if ($i === 0) {
                $this->currentId = $id;
            }
            $this->keys[$id] ??= $key;
        }

        if ($this->currentId === '') {
            throw new RuntimeException('APP_KEY is not set — documents cannot be encrypted.');
        }
    }

    /** APP_KEY + APP_PREVIOUS_KEYS (Laravel's `base64:` notation supported). */
    public static function fromConfig(): self
    {
        $keys = array_merge([config('app.key')], (array) config('app.previous_keys', []));
        $raw = [];
        foreach ($keys as $key) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }
            $key = trim($key);
            $raw[] = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
        }

        return new self($raw);
    }

    /**
     * Encrypts `$in` into `$out` (both streams, read/written sequentially).
     *
     * @param  resource  $in
     * @param  resource  $out
     * @return array{bytes: int, sha256: string} size and sha256 of the plaintext
     */
    public function encryptStream($in, $out): array
    {
        $fileId = random_bytes(16);
        $key = $this->keys[$this->currentId];
        $this->write($out, self::MAGIC.$this->currentId.$fileId.pack('N', self::CHUNK_SIZE));

        $hash = hash_init('sha256');
        $bytes = 0;
        $index = 0;
        $current = $this->read($in, self::CHUNK_SIZE);
        do {
            // A full record may be followed by more data: look ahead so the last record carries the final flag.
            $next = strlen($current) === self::CHUNK_SIZE ? $this->read($in, self::CHUNK_SIZE) : '';
            $final = $next === '';
            $this->writeRecord($out, $key, $fileId, $index++, $final, $current);
            hash_update($hash, $current);
            $bytes += strlen($current);
            $current = $next;
        } while (! $final);

        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }

    /** True when `$header` (the first HEADER_LENGTH bytes of a file) is ours and was written with a known key. */
    public function isEncryptedHeader(string $header): bool
    {
        return strlen($header) >= self::HEADER_LENGTH
            && str_starts_with($header, self::MAGIC)
            && isset($this->keys[substr($header, 8, 8)]);
    }

    /**
     * Decrypts `$in` record by record. Pass `$header` when the first HEADER_LENGTH bytes were already read.
     *
     * @param  resource  $in
     * @return Generator<int, string> plaintext chunks
     *
     * @throws DocumentDecryptionException
     */
    public function decryptChunks($in, ?string $header = null): Generator
    {
        $header ??= $this->read($in, self::HEADER_LENGTH);
        if (strlen($header) !== self::HEADER_LENGTH || ! str_starts_with($header, self::MAGIC)) {
            throw new DocumentDecryptionException('Not an encrypted document.');
        }
        $key = $this->keys[substr($header, 8, 8)] ?? throw new DocumentDecryptionException('Document was encrypted with an unknown key (APP_KEY changed without APP_PREVIOUS_KEYS?).');
        $fileId = substr($header, 16, 16);

        for ($index = 0; ; $index++) {
            $flag = $this->read($in, 1);
            if ($flag !== "\x00" && $flag !== "\x01") {
                throw new DocumentDecryptionException($flag === '' ? 'Encrypted document is truncated.' : 'Encrypted document is corrupted.');
            }
            $meta = $this->read($in, 32);
            if (strlen($meta) !== 32) {
                throw new DocumentDecryptionException('Encrypted document is truncated.');
            }
            $length = (int) unpack('N', substr($meta, 0, 4))[1];
            if ($length > self::MAX_RECORD) {
                throw new DocumentDecryptionException('Encrypted document is corrupted.');
            }
            $cipher = $length > 0 ? $this->read($in, $length) : '';
            if (strlen($cipher) !== $length) {
                throw new DocumentDecryptionException('Encrypted document is truncated.');
            }

            $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, substr($meta, 4, 12), substr($meta, 16, 16), $this->aad($fileId, $index, $flag));
            if ($plain === false) {
                throw new DocumentDecryptionException('Encrypted document failed its integrity check.');
            }
            if ($plain !== '') {
                yield $plain;
            }

            if ($flag === "\x01") {
                if ($this->read($in, 1) !== '') {
                    throw new DocumentDecryptionException('Unexpected data after the final record.');
                }

                return;
            }
        }
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @return array{bytes: int, sha256: string}
     */
    public function decryptStream($in, $out): array
    {
        $hash = hash_init('sha256');
        $bytes = 0;
        foreach ($this->decryptChunks($in) as $chunk) {
            $this->write($out, $chunk);
            hash_update($hash, $chunk);
            $bytes += strlen($chunk);
        }

        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }

    /** @param resource $out */
    private function writeRecord($out, string $key, string $fileId, int $index, bool $final, string $plain): void
    {
        $flag = $final ? "\x01" : "\x00";
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($fileId, $index, $flag), 16);
        if ($cipher === false) {
            throw new RuntimeException('Document encryption failed.');
        }
        $this->write($out, $flag.pack('N', strlen($cipher)).$nonce.$tag.$cipher);
    }

    private function aad(string $fileId, int $index, string $flag): string
    {
        return self::MAGIC.$fileId.pack('J', $index).$flag;
    }

    /**
     * Reads up to `$length` bytes (fewer only at the end of the stream).
     *
     * @param  resource  $in
     */
    private function read($in, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length && ! feof($in)) {
            $part = fread($in, $length - strlen($buffer));
            if ($part === false) {
                throw new RuntimeException('Could not read the document stream.');
            }
            if ($part === '') {
                break;
            }
            $buffer .= $part;
        }

        return $buffer;
    }

    /** @param resource $out */
    private function write($out, string $data): void
    {
        $length = strlen($data);
        for ($written = 0; $written < $length; $written += $n) {
            $n = fwrite($out, $written === 0 ? $data : substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Could not write the document stream.');
            }
        }
    }
}
