<?php

declare(strict_types=1);

namespace BengalStudio\AI\Exceptions;

/**
 * Thrown when a retryable operation exceeds maximum retries.
 */
class RetryException extends AIException
{
    public function __construct(
        public readonly int $maxRetries,
        public readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Maximum retries ({$maxRetries}) exceeded.",
            0,
            $previous
        );
    }
}
