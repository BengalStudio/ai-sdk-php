<?php

declare(strict_types=1);

namespace BengalStudio\AI\Exceptions;

/**
 * Thrown when an API call fails.
 */
class APICallException extends AIException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly mixed $responseBody = null,
        public readonly ?string $url = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }
}
