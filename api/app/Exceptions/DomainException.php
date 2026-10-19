<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Business-rule violation rendered as JSON {message, errors, code} (SPEC §6 conventions).
 */
class DomainException extends RuntimeException
{
    /** @param array<string, array<int, string>> $errors */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'domain_error',
        private readonly int $status = 422,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, array<int, string>> $errors */
    public static function make(string $translationKey, string $code = 'domain_error', int $status = 422, array $params = [], array $errors = []): self
    {
        return new self(__($translationKey, $params), $code, $status, $errors);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, array<int, string>>|\stdClass */
    public function errors(): array|\stdClass
    {
        return $this->errors === [] ? new \stdClass : $this->errors;
    }
}
