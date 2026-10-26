<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** SEC-01 — an encrypted document could not be decrypted (wrong key, truncated or tampered file). */
class DocumentDecryptionException extends RuntimeException {}
